<?php
/**
 * Part of the ETD Framework Table Package
 *
 * @copyright   Copyright (C) 2015 ETD Solutions, SARL Etudoo. Tous droits réservés.
 * @license     Apache License 2.0; see LICENSE
 * @author      ETD Solutions http://etd-solutions.com
 */

namespace EtdSolutions\Table;

use EtdSolutions\User\User;
use Joomla\Language\Text;
use Joomla\Utilities\ArrayHelper;

defined('_JEXEC') or die;

/**
 * Représentation d'une table supportant la gestion d'un arbre dans la base de données.
 *
 * @property int parent_id  Clé primaire du noeud parent.
 * @property int level      Niveau du noeud dans l'arbre.
 * @property int lft        Valeur de gauche du noeud pour gérer son emplacement dans l'arbre.
 * @property int rgt        Valeur de droite du noeud pour gérer son emplacement dans l'arbre.
 *
 */
abstract class NestedTable extends Table {

    /**
     * Propriété pour garder le type de positionnement à utiliser quand on stocke la ligne.
     * Valeurs possibles : ['before', 'after', 'first-child', 'last-child'].
     *
     * @var    string
     */
    protected $_location;

    /**
     * Propriété pour garder la clé primaire de l'emplacement du noeud de référence à utiliser
     * quand on stocke une ligne. Une combinaison du type de l'emplacement et du noeud de référence
     * décrit où stocker le noeud courante dans l'arbre.
     *
     * @var    integer
     */
    protected $_location_id;

    /**
     * Un tableau de valeurs mises en cache pendant les process récursifs.
     *
     * @var    array
     */
    protected $cache = array();

    /**
     * Méthode pour récupérer un noeud et tous ses enfants.
     *
     * @param   int    $pk    La clé primaire du noeud pour lequel récupérer l'arbre.
     * @param   string $where La clause WHERE à utiliser pour limiter la sélection de ligne.
     *
     * @return  array|bool Un tableau des noeuds en cas de succès, false sinon.
     *
     * @throws  \RuntimeException en cas d'erreur db.
     */
    public function getTree($pk = null, $where = null) {

        $k  = $this->getPk();
        $pk = (is_null($pk)) ? $this->getProperty($k) : $pk;

        // On récupère le noeud et ses enfants comme un arbre.
        $db    = $this->getDb();
        $query = $db->getQuery(true)
                    ->select('n.*')
                    ->from($this->getTable() . ' AS n, ' . $this->getTable() . ' AS p')
                    ->where('n.lft BETWEEN p.lft AND p.rgt')
                    ->where('p.' . $k . ' = ' . (int)$pk)
                    ->order('n.lft');

        if ($where) {
            $query->where($where);
        }

        return $db->setQuery($query)
                  ->loadObjectList();
    }

    /**
     * Méthode pour déterminer si un noeud est une feuille dans un arbre (n'a pas d'enfants).
     *
     * @param   integer $pk La clé primaire du noeud à vérifier.
     *
     * @return  boolean  True si c'est une feuille, false sinon ou null si le noeud n'existe pas.
     *
     * @throws  \RuntimeException en cas d'erreur db.
     */
    public function isLeaf($pk = null) {

        $k    = $this->getPk();
        $pk   = (is_null($pk)) ? $this->getProperty($k) : $pk;
        $node = $this->getNode($pk);

        // On récupère le noeud par la clé primaire.
        if (empty($node)) {
            return null;
        }

        // Le noeud est une feuille.
        return (($node->rgt - $node->lft) == 1);
    }

    /**
     * Méthode pour définir l'emplacement d'un noeud dans l'arbre. Cette méthode ne
     * sauvegarde pas le nouvel emplacement dans la base de données, mais va le définir
     * dans le Table donc quand le noeud sera stocké il le sera à un nouvel emplacement.
     *
     * @param   integer $referenceId La clé primaire du nouveau noeud de référence.
     * @param   string  $position    Le type d'emplacement. ['before', 'after', 'first-child', 'last-child']
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException
     */
    public function setLocation($referenceId, $position = 'after') {

        // On s'assure que le nouvel emplacement est valide.
        if (($position != 'before') && ($position != 'after') && ($position != 'first-child') && ($position != 'last-child')) {
            throw new \InvalidArgumentException(sprintf('%s::setLocation(%d, *%s*)', get_class($this), $referenceId, $position));
        }

        // On définit les propriétés du nouvel emplacement.
        $this->_location    = $position;
        $this->_location_id = $referenceId;
    }

    /**
     * Méthode pour déplacer une ligne dans la séquence d'ordre d'un groupe de lignes définie par la clause WHERE SQL.
     * Des nombres négatifs déplacent la ligne vers le haut et des nombres positifs vers le bas.
     *
     * @param   integer $delta La direction et magnitude pour déplacer la ligne.
     * @param   string  $where La clause WHERE à utiliser pour limiter la sélection de ligne.
     *
     * @return  mixed    Boolean true en cas de succès.
     */
    public function move($delta, $where = '') {

        $k  = $this->getPk();
        $pk = $this->getProperty($k);
        $db = $this->getDb();

        $query = $db->getQuery(true)
                    ->select($k)
                    ->from($this->getTable())
                    ->where('parent_id = ' . $this->getProperty('parent_id'));

        if ($where) {
            $query->where($where);
        }

        if ($delta > 0) {
            $query->where('rgt > ' . $this->getProperty('rgt'))
                  ->order('rgt ASC');
            $position = 'after';
        } else {
            $query->where('lft < ' . $this->getProperty('lft'))
                  ->order('lft DESC');
            $position = 'before';
        }

        $db->setQuery($query);
        $referenceId = $db->loadResult();

        if ($referenceId) {
            return $this->moveByReference($referenceId, $position, $pk, $where);
        } else {
            return false;
        }
    }

    /**
     * Méthode pour déplacer un noeud et ses enfants vers un nouvel emplacement dans l'arbre.
     *
     * @param   integer $referenceId La clé primaire du noeud de référence.
     * @param   string  $position    Le type d'emplacement. ['before', 'after', 'first-child', 'last-child']
     * @param   integer $pk          La clé primaire du noeud à déplacer.
     * @param   string  $where       La clause WHERE à utiliser pour limiter la sélection de ligne.
     *
     * @return  boolean  True en cas de succès.
     *
     * @throws  \RuntimeException en cas d'erreur db.
     */
    public function moveByReference($referenceId, $position = 'after', $pk = null, $where = null) {

        $k  = $this->getPk();
        $pk = (is_null($pk)) ? $this->getProperty($k) : $pk;
        $db = $this->getDb();

        // On récupère le noeud par l'id.
        if (!$node = $this->getNode($pk)) {
            return false;
        }

        // On récupère les ids des noeuds fils.
        $query = $db->getQuery(true)
                    ->select($k)
                    ->from($this->getTable())
                    ->where('lft BETWEEN ' . (int)$node->lft . ' AND ' . (int)$node->rgt);

        if ($where) {
            $query->where($where);
        }

        $children = $db->setQuery($query)
                       ->loadColumn();

        // On ne peut pas déplacer le noeud pour devenir un enfant de lui-même.
        if (in_array($referenceId, $children)) {
            $e = new \UnexpectedValueException(sprintf('%s::moveByReference(%d, %s, %d) parenting to child.', get_class($this), $referenceId, $position, $pk));
            $this->addError($e);

            return false;
        }

        // On verrouille la table.
        if (!$this->lock()) {
            return false;
        }

        /*
         * On déplace la branche en dehors du set en utilisant des valeurs left et right négatives.
         */
        $query->clear()
              ->update($this->getTable())
              ->set('lft = lft * (-1), rgt = rgt * (-1)')
              ->where('lft BETWEEN ' . (int)$node->lft . ' AND ' . (int)$node->rgt);

        if ($where) {
            $query->where($where);
        }

        $db->setQuery($query)
           ->execute();

        /*
         * On ferme le trou dans l'arbre qui a été ouvert en enlevant la branche du set.
         */
        // On compresse les valeurs left.
        $query->clear()
              ->update($this->getTable())
              ->set('lft = lft - ' . (int)$node->width)
              ->where('lft > ' . (int)$node->rgt);

        if ($where) {
            $query->where($where);
        }

        $db->setQuery($query)
           ->execute();

        // On compresse les valeurs right.
        $query->clear()
              ->update($this->getTable())
              ->set('rgt = rgt - ' . (int)$node->width)
              ->where('rgt > ' . (int)$node->rgt);

        if ($where) {
            $query->where($where);
        }

        $db->setQuery($query)
           ->execute();

        // On déplace l'arbre relativement au noeud de référence.
        if ($referenceId) {

            // On récupère le noeud de référencence par la clé primaire.
            if (!$reference = $this->getNode($referenceId)) {
                $this->unlock();

                return false;
            }

            // On récupère la position pour déplacer l'arbre et réinsérer le noeud.
            if (!$repositionData = $this->getTreeRepositionData($reference, $node->width, $position)) {
                $this->unlock();

                return false;
            }

        } // On est en train de déplacer l'arbre pour devenir le dernier enfant du noeud racine.
        else {
            // On récupère le dernier noeud comme noeud de référence.
            $query->clear()
                  ->select($this->getPk() . ', parent_id, level, lft, rgt')
                  ->from($this->getTable())
                  ->where('parent_id = 0')
                  ->order('lft DESC');

            if ($where) {
                $query->where($where);
            }

            $db->setQuery($query, 0, 1);
            $reference = $db->loadObject();

            // On récupère la position pour réinsérer le noeud après la racine.
            if (!$repositionData = $this->getTreeRepositionData($reference, $node->width, 'last-child')) {
                $this->unlock();

                return false;
            }
        }

        /*
         * On crée un espace dans le set au nouvel emplacement pour la branche déplacée.
         */

        // On déplace les valeurs left.
        $query->clear()
              ->update($this->getTable())
              ->set('lft = lft + ' . (int)$node->width)
              ->where($repositionData->left_where);

        if ($where) {
            $query->where($where);
        }

        $db->setQuery($query)
           ->execute();

        // On déplace les valeurs right.
        $query->clear()
              ->update($this->getTable())
              ->set('rgt = rgt + ' . (int)$node->width)
              ->where($repositionData->right_where);

        if ($where) {
            $query->where($where);
        }

        $db->setQuery($query)
           ->execute();

        /*
         * On calcul l'offset entre l'endroit où était le noeud et où il sera dans l'arbre.
         */
        $offset      = $repositionData->new_lft - $node->lft;
        $levelOffset = $repositionData->new_level - $node->level;

        // On remet les noeuds à leur emplacement dans l'arbre en utilisant les offsets calculés.
        $query->clear()
              ->update($this->getTable())
              ->set('rgt = ' . (int)$offset . ' - rgt')
              ->set('lft = ' . (int)$offset . ' - lft')
              ->set('level = level + ' . (int)$levelOffset)
              ->where('lft < 0');

        if ($where) {
            $query->where($where);
        }

        $db->setQuery($query)
           ->execute();

        // On définit le bon id du parent pour le noeud déplacé si besoin.
        if ($node->parent_id != $repositionData->new_parent_id) {
            $query = $db->getQuery(true)
                        ->update($this->getTable());

            // On met à jour le titre et l'alias s'il existe dans la table.
            $fields = $this->getFields();

            if (array_key_exists('title', $fields) && $this->getProperty('title') !== null) {
                $query->set('title = ' . $db->quote($this->getProperty('title')));
            }

            if (array_key_exists('alias', $fields) && $this->getProperty('alias') !== null) {
                $query->set('alias = ' . $db->quote($this->getProperty('alias')));
            }

            $query->set('parent_id = ' . (int)$repositionData->new_parent_id)
                  ->where($this->getPk() . ' = ' . (int)$node->$k);

            if ($where) {
                $query->where($where);
            }

            $db->setQuery($query)
               ->execute();
        }

        $this->unlock();

        // On définit quelques propriétés
        $this->setProperty('parent_id', $repositionData->new_parent_id);
        $this->setProperty('level', $repositionData->new_level);
        $this->setProperty('lft', $repositionData->new_lft);
        $this->setProperty('rgt', $repositionData->new_rgt);

        return true;
    }

    /**
     * Méthode pour supprimer un noeud et, le cas échéant, ses enfants.
     *
     * @param   integer $pk       La clé primaire du noeud à supprimer.
     * @param   boolean $children True pour supprimer ses enfants, false pour déplacer d'un niveau au dessus.
     * @param   string  $where    La clause WHERE à utiliser pour limiter la sélection de ligne.
     *
     * @return  boolean  True en cas de succès.
     */
    public function delete($pk = null, $children = true, $where = null) {

        $k  = $this->getPk();
        $pk = (is_null($pk)) ? $this->getProperty($k) : $pk;
        $db = $this->getDb();

        $this->lock();

        // On récupère le noeud.
        $node = $this->getNode($pk);

        if (empty($node)) {
            $this->unlock();

            return false;
        }

        $query = $db->getQuery(true);

        // Doit-on supprimer tous les enfants avec le noeud ?
        if ($children) {

            $query->clear()
                  ->delete($this->getTable())
                  ->where('lft BETWEEN ' . (int)$node->lft . ' AND ' . (int)$node->rgt);

            if ($where) {
                $query->where($where);
            }

            $db->setQuery($query)
               ->execute();

            // Compress the left values.
            $query->clear()
                  ->update($this->getTable())
                  ->set('lft = lft - ' . (int)$node->width)
                  ->where('lft > ' . (int)$node->rgt);

            if ($where) {
                $query->where($where);
            }

            $db->setQuery($query)
               ->execute();

            // Compress the right values.
            $query->clear()
                  ->update($this->getTable())
                  ->set('rgt = rgt - ' . (int)$node->width)
                  ->where('rgt > ' . (int)$node->rgt);

            if ($where) {
                $query->where($where);
            }

            $db->setQuery($query)
               ->execute();

        } // On laisse les enfants et on les déplace d'un niveau au dessus.
        else {

            // On supprime le noeud.
            $query->clear()
                  ->delete($this->getTable())
                  ->where('lft = ' . (int)$node->lft);

            if ($where) {
                $query->where($where);
            }

            $db->setQuery($query)
               ->execute();

            // On déplace les enfants.
            $query->clear()
                  ->update($this->getTable())
                  ->set('lft = lft - 1')
                  ->set('rgt = rgt - 1')
                  ->set('level = level - 1')
                  ->where('lft BETWEEN ' . (int)$node->lft . ' AND ' . (int)$node->rgt);

            if ($where) {
                $query->where($where);
            }

            $db->setQuery($query)
               ->execute();

            // On ajuste toutes les valeurs parent pour les enfants directs.
            $query->clear()
                  ->update($this->getTable())
                  ->set('parent_id = ' . (int)$node->parent_id)
                  ->where('parent_id = ' . (int)$node->$k);
            $db->setQuery($query)
               ->execute();

            $query->clear()
                  ->update($this->getTable())
                  ->set('lft = lft - 2')
                  ->where('lft > ' . (int)$node->rgt);

            if ($where) {
                $query->where($where);
            }

            $db->setQuery($query)
               ->execute();

            $query->clear()
                  ->update($this->getTable())
                  ->set('rgt = rgt - 2')
                  ->where('rgt > ' . (int)$node->rgt);

            if ($where) {
                $query->where($where);
            }

            $db->setQuery($query)
               ->execute();
        }

        $this->unlock();

        return true;
    }

    /**
     * Contrôle que l'objet est valide et peut être stocké.
     *
     * Cette méthode contrôle que le parent_id est différent de zéro et qu'il existe dans la base.
     * Le noeud racine (parent_id = 0) ne peut être manipulé par cette classe.
     *
     * @return  boolean  True si tous les controles sont bons.
     *
     * @throws  \Exception
     * @throws  \RuntimeException on database error.
     * @throws  \UnexpectedValueException
     */
    public function check() {

        $db = $this->getDb();
        $this->setProperty('parent_id', (int)$this->getProperty('parent_id'));

        // On contrôle que le champ parent_id est valide.
        if ($this->getProperty('parent_id') == 0) {
            throw new \UnexpectedValueException(sprintf('Invalid `parent_id` [%d] in %s', $this->getProperty('parent_id'), get_class($this)));
        }

        $query = $db->getQuery(true)
                    ->select('COUNT(' . $this->getPk() . ')')
                    ->from($this->getTable())
                    ->where($this->getPk() . ' = ' . $this->getProperty('parent_id'));

        $res = $db->setQuery($query)
                  ->loadResult();
        if (!$res) {
            throw new \UnexpectedValueException(sprintf('Invalid `parent_id` [%d] in %s', $this->getProperty('parent_id'), get_class($this)));
        }

        return true;
    }

    /**
     * Méthode pour stocker le noeud dans la base de données.
     *
     * @param   boolean $updateNulls True pour mettre à jour les valeurs nulles aussi.
     * @param   string  $where       La clause WHERE à utiliser pour limiter la sélection de ligne.
     *
     * @return  boolean  True en cas de succès.
     *
     * @throws \UnexpectedValueException
     *
     */
    public function store($updateNulls = false, $where = null) {

        $k = $this->getPk();

        /*
         * Si la clé primaire est vide, on assume que l'on est en train d'insérer un nouveau noeud
         * dans l'arbre. Nous devons donc déterminer où dans l'arbre sera inséré ce noeud.
         */
        if (empty($this->$k)) {

            /*
             * On est en train d'insérer un noeud quelque part dans l'arbre avec un noeud de référence connu.
             * On doit faire une place au nouveau noeud et définir les valeurs left et right avant d'insérer
             * la ligne.
             */
            if ($this->_location_id >= 0) {

                // On verrouille la table.
                if (!$this->lock()) {
                    return false;
                }

                // On insert le noeud relativement au dernier noeud racine.
                if ($this->_location_id == 0) {

                    // On récupère le dernier noeud comme référence.
                    $query = $this->getDb()
                                  ->getQuery(true)
                                  ->select($this->getPk() . ', parent_id, level, lft, rgt')
                                  ->from($this->getTable())
                                  ->where('parent_id = 0')
                                  ->order('lft DESC');

                    if ($where) {
                        $query->where($where);
                    }

                    $this->getDb()
                         ->setQuery($query, 0, 1);
                    $reference = $this->getDb()
                                      ->loadObject();

                } else { // On a un vrai noeud de définie comme référence.

                    // On récupère le noeud avec la clé primaire.
                    if (!$reference = $this->getNode($this->_location_id)) {
                        $this->unlock();

                        return false;
                    }
                }

                // On récupère les données de repositionnement.
                if (!($repositionData = $this->getTreeRepositionData($reference, 2, $this->_location))) {
                    $this->unlock();

                    return false;
                }

                $query = $this->getDb()
                              ->getQuery(true)
                              ->update($this->getTable())
                              ->set('lft = lft + 2')
                              ->where($repositionData->left_where);

                if ($where) {
                    $query->where($where);
                }

                $this->getDb()
                     ->setQuery($query)
                     ->execute();

                // On crée l'espace dans l'arbre pour accueillir le noeud.
                $query->clear()
                      ->update($this->getTable())
                      ->set('rgt = rgt + 2')
                      ->where($repositionData->right_where);

                if ($where) {
                    $query->where($where);
                }

                $this->getDb()
                     ->setQuery($query)
                     ->execute();

                // Set the object values.
                $this->setProperty('parent_id', $repositionData->new_parent_id);
                $this->setProperty('level', $repositionData->new_level);
                $this->setProperty('lft', $repositionData->new_lft);
                $this->setProperty('rgt', $repositionData->new_rgt);
            } else {
                // Les ids des parent négatifs sont invalides.
                throw new \UnexpectedValueException(sprintf('%s::store() used a negative _location_id', get_class($this)));
            }
        } else { // Si on a une clé primaire, on est en train de mettre à jour un noeud dans l'arbre.

            // Si le positionnement a été définit, on déplace le noeud vers sa nouvelle position.
            if ($this->_location_id > 0) {
                if (!$this->moveByReference($this->_location_id, $this->_location, $this->$k, $where)) {
                    return false;
                }
            }

            // On verouille la table.
            if (!$this->lock()) {
                return false;
            }
        }

        $result = parent::store($updateNulls);

        // On déverouille la table.
        $this->unlock();

        return $result;
    }

    /**
     * Méthode pour définir l'état de publication d'un noeud ou d'une liste de noeud dans la base.
     * La méthode ne permettra pas de définir un état de publication supérieur à n'importe quel
     * noeud parent.
     *
     * @param   mixed   $pks      Un tableau optionnel de clés primaires à mettre à jour. Si non
     *                            définie, la valeur de l'instance est utilisée.
     * @param   integer $state    L'état de publication. eg. [0 = dépublié, 1 = publié]
     * @param   string  $where    La clause WHERE à utiliser pour limiter la sélection de ligne.
     *
     * @return  boolean  True en cas de succès.
     *
     * @throws \UnexpectedValueException
     */
    public function publish($pks = null, $state = 1, $where = null) {

        $k     = $this->getPk();
        $query = $this->getDb()
                      ->getQuery(true);

        // On nettoie les entrées.
        ArrayHelper::toInteger($pks);
        $userId = (int)User::getInstance()->id;
        $state  = (int)$state;

        // Si $state > 1, on autorise le changement d'état meme si l'ancètre a un état inférieur
        // (par exemple, on peut changer l'état d'un enfant à Archivé (2) si un ancètre est Publié (1)
        $compareState = ($state > 1) ? 1 : $state;

        // S'il n'y a pas de clé primaires, on regarde si l'instance en a une.
        if (empty($pks)) {
            if ($this->$k) {
                $pks = explode(',', $this->$k);
            } // rien à faire ici ...
            else {
                throw new \UnexpectedValueException(sprintf('%s::publish(%s, %d, %d) empty.', get_class($this), $pks, $state, $userId));
            }
        }

        // On détermine le bon champ.
        $fields = $this->getFields();
        $field  = null;
        if (in_array('published', $fields)) {
            $field = 'published';
        } elseif (in_array('state', $fields)) {
            $field = 'state';
        } else {
            $this->addError(Text::_('APP_ERROR_TABLE_NO_PUBLISHED_FIELD'));

            return false;
        }

        // On passe sur chacune des clé primaire.
        foreach ($pks as $pk) {

            // On récupère le noeud.
            if (!$node = $this->getNode($pk)) {
                return false;
            }

            // Si un des parents a un état plus petit, on ne peut continuer.
            if ($node->parent_id) {

                // On récupère les noeuds ancètres qui ont un plus petit état.
                $query->clear()
                      ->select('n.' . $k)
                      ->from($this->getDb()
                                  ->quoteName($this->getTable()) . ' AS n')
                      ->where('n.lft < ' . (int)$node->lft)
                      ->where('n.rgt > ' . (int)$node->rgt)
                      ->where('n.parent_id > 0')
                      ->where('n.' . $this->getDb()
                                          ->quoteName($field) . ' < ' . (int)$compareState);

                if ($where) {
                    $query->where($where);
                }

                // On récupère juste une ligne (c'est déjà une de trop !).
                $this->getDb()
                     ->setQuery($query, 0, 1);

                $rows = $this->getDb()
                             ->loadColumn();

                if (!empty($rows)) {
                    throw new \UnexpectedValueException(sprintf('%s::publish(%s, %d, %d) ancestors have lower state.', get_class($this), $pks, $state, $userId));
                }
            }

            // On met à jour en cascade les états.
            $query->clear()
                  ->update($this->getDb()
                                ->quoteName($this->getTable()))
                  ->set($this->getDb()
                             ->quoteName($field) . ' = ' . (int)$state)
                  ->where('(lft > ' . (int)$node->lft . ' AND rgt < ' . (int)$node->rgt . ') OR ' . $k . ' = ' . (int)$pk);

            if ($where) {
                $query->where($where);
            }

            $this->getDb()
                 ->setQuery($query)
                 ->execute();

        }

        if (in_array($this->$k, $pks)) {
            $this->setProperty($field, $state);
        }

        $this->clearErrors();

        return true;
    }

    /**
     * Méthode pour déplacer un noeud d'une place vers la gauche au même niveau.
     *
     * @param   integer $pk    Les clés primaires à déplacer.
     * @param   string  $where La clause WHERE à utiliser pour limiter la sélection de ligne.
     *
     * @return  boolean  True en cas de succès.
     *
     * @throws  \RuntimeException en cas d'erreur db.
     */
    public function orderUp($pk, $where = null) {

        $k  = $this->getPk();
        $pk = (is_null($pk)) ? $this->getProperty($k) : $pk;

        if (!$this->lock()) {
            return false;
        }

        $node = $this->getNode($pk);

        if (empty($node)) {
            $this->unlock();

            return false;
        }

        $sibling = $this->getNode($node->lft - 1, 'right', $where);

        if (empty($sibling)) {
            $this->unlock();

            return false;
        }

        try {

            $query = $this->getDb()
                          ->getQuery(true)
                          ->select($this->getPk())
                          ->from($this->getTable())
                          ->where('lft BETWEEN ' . (int)$node->lft . ' AND ' . (int)$node->rgt);

            if ($where) {
                $query->where($where);
            }

            $children = $this->getDb()
                             ->setQuery($query)
                             ->loadColumn();

            $query->clear()
                  ->update($this->getTable())
                  ->set('lft = lft - ' . (int)$sibling->width)
                  ->set('rgt = rgt - ' . (int)$sibling->width)
                  ->where('lft BETWEEN ' . (int)$node->lft . ' AND ' . (int)$node->rgt);

            if ($where) {
                $query->where($where);
            }

            $this->getDb()
                 ->setQuery($query)
                 ->execute();

            $query->clear()
                  ->update($this->getTable())
                  ->set('lft = lft + ' . (int)$node->width)
                  ->set('rgt = rgt + ' . (int)$node->width)
                  ->where('lft BETWEEN ' . (int)$sibling->lft . ' AND ' . (int)$sibling->rgt)
                  ->where($this->getPk() . ' NOT IN (' . implode(',', $children) . ')');

            if ($where) {
                $query->where($where);
            }

            $this->getDb()
                 ->setQuery($query)
                 ->execute();

        } catch (\RuntimeException $e) {
            $this->unlock();
            throw $e;
        }

        $this->unlock();

        return true;
    }

    /**
     * Méthode pour déplacer un noeud d'une place vers la droite au même niveau.
     *
     * @param   integer $pk    Les clés primaires à déplacer.
     * @param   string  $where La clause WHERE à utiliser pour limiter la sélection de ligne.
     *
     * @return  boolean  True en cas de succès.
     *
     * @throws  \RuntimeException en cas d'erreur db.
     */
    public function orderDown($pk, $where = null) {

        $k  = $this->getPk();
        $pk = (is_null($pk)) ? $this->getProperty($k) : $pk;

        if (!$this->lock()) {
            return false;
        }

        $node = $this->getNode($pk);

        if (empty($node)) {
            $this->unlock();

            return false;
        }

        $sibling = $this->getNode($node->rgt + 1, 'left', $where);

        if (empty($sibling)) {
            $this->unlock();

            return false;
        }

        try {

            $query = $this->getDb()
                          ->getQuery(true)
                          ->select($this->getPk())
                          ->from($this->getTable())
                          ->where('lft BETWEEN ' . (int)$node->lft . ' AND ' . (int)$node->rgt);

            if ($where) {
                $query->where($where);
            }

            $children = $this->getDb()
                             ->setQuery($query)
                             ->loadColumn();

            $query->clear()
                  ->update($this->getTable())
                  ->set('lft = lft + ' . (int)$sibling->width)
                  ->set('rgt = rgt + ' . (int)$sibling->width)
                  ->where('lft BETWEEN ' . (int)$node->lft . ' AND ' . (int)$node->rgt);

            if ($where) {
                $query->where($where);
            }

            $this->getDb()
                 ->setQuery($query)
                 ->execute();

            $query->clear()
                  ->update($this->getTable())
                  ->set('lft = lft - ' . (int)$node->width)
                  ->set('rgt = rgt - ' . (int)$node->width)
                  ->where('lft BETWEEN ' . (int)$sibling->lft . ' AND ' . (int)$sibling->rgt)
                  ->where($this->getPk() . ' NOT IN (' . implode(',', $children) . ')');

            if ($where) {
                $query->where($where);
            }

            $this->getDb()
                 ->setQuery($query)
                 ->execute();

        } catch (\RuntimeException $e) {
            $this->unlock();
            throw $e;
        }

        $this->unlock();

        return true;
    }

    /**
     * Donne l'ID de l'élément racine dans l'arbre.
     *
     * @param   string $where Une clause WHERE pour trouve le parent dans une sélection de lignes.
     *
     * @return  mixed  La clé primaire de la ligne racine, false sinon.
     *
     * @throws \UnexpectedValueException
     */
    public function getRootId($where = null) {

        // Get the root item.
        $k = $this->getPk();

        // Test for a unique record with parent_id = 0
        $query = $this->getDb()
                      ->getQuery(true)
                      ->select($k)
                      ->from($this->getTable())
                      ->where('parent_id = 0');

        if ($where) {
            $query->where($where);
        }

        $result = $this->getDb()
                       ->setQuery($query)
                       ->loadColumn();

        if (count($result) == 1) {
            return $result[0];
        }

        // Test for a unique record with lft = 0
        $query->clear()
              ->select($k)
              ->from($this->getTable())
              ->where('lft = 0');

        if ($where) {
            $query->where($where);
        }

        $result = $this->getDb()
                       ->setQuery($query)
                       ->loadColumn();

        if (count($result) == 1) {
            return $result[0];
        }

        $fields = $this->getFields();

        if (array_key_exists('alias', $fields)) {
            // Test for a unique record alias = root
            $query->clear()
                  ->select($k)
                  ->from($this->getTable())
                  ->where('alias = ' . $this->getDb()
                                            ->quote('root'));

            if ($where) {
                $query->where($where);
            }

            $result = $this->getDb()
                           ->setQuery($query)
                           ->loadColumn();

            if (count($result) == 1) {
                return $result[0];
            }
        }

        throw new \UnexpectedValueException(sprintf('%s::getRootId(%s)', get_class($this), print_r($where, true)));

    }

    /**
     * Méthode pour reconstruire récursivement l'arbre en entier.
     *
     * @param   integer $parentId La racine de l'arbre à reconstruire.
     * @param   integer $leftId   L'id de gauche avec lequel reconstuire l'arbre.
     * @param   integer $level    Le niveau à donner aux noeuds courants.
     * @param   string  $path     Le chemin vers les noeuds courants.
     * @param   string  $where    Une clause WHERE pour réduire les lignes à reconstruire.
     *
     * @return  integer  1 + la valeur de droite de la racine en cas de succès, false en cas d'échec
     *
     * @throws  \RuntimeException en cas d'erreur db.
     */
    public function rebuild($parentId = null, $leftId = 0, $level = 0, $path = '', $where = null) {

        // Si aucun parent n'est donné, on essaye de le trouver.
        if ($parentId === null) {
            // Get the root item.
            $parentId = $this->getRootId($where);

            if ($parentId === false) {
                return false;
            }
        }

        $query = $this->getDb()
                      ->getQuery(true);

        // On construit la strucuture de la requête récursive.
        if (!isset($this->cache['rebuild.sql'])) {
            $query->clear()
                  ->from($this->getTable())
                  ->where('parent_id = %d');

            if ($where) {
                $query->where($where);
            }

            if (property_exists($this, 'alias')) {
                $query->select($this->getPk() . ', alias');
            } else {
                $query->select($this->getPk());
            }

            // Si la table a un champ d'ordre, on l'utilise.
            if (property_exists($this, 'ordering')) {
                $query->order('parent_id, ordering, lft');
            } else {
                $query->order('parent_id, lft');
            }
            $this->cache['rebuild.sql'] = (string)$query;
        }

        // On assemble la requête pour trouver les enfants du noeuds.
        $this->getDb()
             ->setQuery(sprintf($this->cache['rebuild.sql'], (int)$parentId));

        $children = $this->getDb()
                         ->loadObjectList();

        // La valeur de droite du noeud est la gauche + 1.
        $rightId = $leftId + 1;

        // On exécute cette fonctionne récursivement sur tous les enfants.
        foreach ($children as $node) {
            /*
             * $rightId est la valeur de droite courante, qui est incrémentée lors du retour de la récursion.
             * On incrémente le niveau de tous les enfants.
             * On ajoute l'alias de l'élément au chemin
             */
            if (property_exists($node, 'alias')) {
                $rightId = $this->rebuild($node->{$this->getPk()}, $rightId, $level + 1, $path . (empty($path) ? '' : '/') . $node->alias, $where);
            } else {
                $rightId = $this->rebuild($node->{$this->getPk()}, $rightId, $level + 1, '', $where);
            }

            // Si il y a un problème de mise à jour, on retourne false pour arrêter la récursion.
            if ($rightId === false) {
                return false;
            }
        }

        // On a la valeur de gauche, maintenant on va traiter les
        // enfants du noeud car on a aussi celle de droite.
        $query->clear()
              ->update($this->getTable())
              ->set('lft = ' . (int)$leftId)
              ->set('rgt = ' . (int)$rightId)
              ->set('level = ' . (int)$level)
              ->where($this->getPk() . ' = ' . (int)$parentId);

        if ($where) {
            $query->where($where);
        }

        if (property_exists($this, 'path')) {
            $query->set('path = ' . $this->getDb()
                                         ->quote($path));
        }

        $this->getDb()
             ->setQuery($query)
             ->execute();

        // On returne la valeur de droite du noeud + 1.
        return $rightId + 1;
    }

    /**
     * Méthode pour reconstruire le chemin du noeud grâce aux valeurs alias des noeuds
     * depuis le noeud courant au noeud racine de l'arbre.
     *
     * @param   integer $pk La clé primaire du noeud
     *
     * @return  boolean  True en cas de succès.
     */
    public function rebuildPath($pk = null) {

        $fields = $this->getFields();

        // S'il n'y a pas de champ "alias" ou de chmp "path", on retourne juste "true".
        if (!array_key_exists('alias', $fields) || !array_key_exists('path', $fields)) {
            return true;
        }

        $k  = $this->getPk();
        $pk = (is_null($pk)) ? $this->$k : $pk;
        $db = $this->getDb();

        // On récupère les alias pour le chemin du noeud jusqu'au noeud racine.
        $query = $db->getQuery(true)
                    ->select('p.alias')
                    ->from($this->getTable() . ' AS n, ' . $this->getTable() . ' AS p')
                    ->where('n.lft BETWEEN p.lft AND p.rgt')
                    ->where('n.' . $this->getPk() . ' = ' . (int)$pk)
                    ->order('p.lft');
        $db->setQuery($query);

        $segments = $db->loadColumn();

        // On s'assure de retirer le chemin de la racine s'il existe dans la liste.
        if ($segments[0] == 'root') {
            array_shift($segments);
        }

        // On construit le chemin.
        $path = trim(implode('/', $segments), ' /\\');

        // On met à jour le champ "path" pour le noeud.
        $query->clear()
              ->update($this->getTable())
              ->set('path = ' . $db->quote($path))
              ->where($this->getPk() . ' = ' . (int)$pk);

        $db->setQuery($query)
           ->execute();

        // On met à jour le chemin dans l'instance.
        $this->setProperty('path', $path);

        return true;
    }

    /**
     * Méthode pour remettre à zero les informations de positionnement de l'instance.
     *
     * @return  NestedTable Cette instance pour le chainage.
     */
    public function reset() {

        $this->setLocation(0);

        return $this;
    }

    /**
     * Méthode pour mettre à jour l'ordre des lignes.
     *
     * @param   array  $idArray   Les clés primaires des lignes à réordonner.
     * @param   array  $lft_array Les valeurs "lft" des lignes à réordonner.
     * @param   string $where     Une clause WHERE pour sélectionner les lignes à réordonner.
     *
     * @return  integer  1 + la valeur "rgt" de la racine en cas de succès, false sinon.
     *
     * @throws  \Exception en cas d'erreur db.
     */
    public function saveorder($idArray = null, $lft_array = null, $where = null) {

        $db = $this->getDb();

        try {
            $query = $db->getQuery(true);

            // On valide les tableaux.
            if (is_array($idArray) && is_array($lft_array) && count($idArray) == count($lft_array)) {
                for ($i = 0, $count = count($idArray); $i < $count; $i++) {

                    // On met à jour les lignes pour changer la valeur lft.
                    $query->clear()
                          ->update($this->getTable())
                          ->set('lft = ' . (int)$lft_array[$i])
                          ->where($this->getPk() . ' = ' . (int)$idArray[$i]);

                    if ($where) {
                        $query->where($where);
                    }

                    $db->setQuery($query)
                       ->execute();

                }

                return $this->rebuild(null, 0, 0, '', $where);
            } else {
                return false;
            }
        } catch (\Exception $e) {
            $this->unlock();
            throw $e;
        }
    }

    /**
     * Méthode pour récupérer les propriérés d'un noeud dans l'arbre.
     *
     * @param   integer $id    La valeur utilisée pour rechercher le noeud.
     * @param   string  $key   Une clé facultative pour rechercher le noeud. (parent | left | right).
     *                         Si omis, la clé primaire du table est utilisée.
     * @param   string  $where Une clause WHERE pour sélectionner les lignes à réordonner.
     *
     * @return  object|false    Un objet représentant le noeud ou false en cas d'échec.
     *
     * @throws  \RuntimeException en cas d'erreur db.
     */
    protected function getNode($id, $key = null, $where = null) {

        // On détermine sur quelle clé on doit se baser pour récupérer le noeud.
        switch ($key) {
            case 'parent':
                $k = 'parent_id';
                break;

            case 'left':
                $k = 'lft';
                break;

            case 'right':
                $k = 'rgt';
                break;

            default:
                $k = $this->getPk();
                break;
        }

        // On récupère le noeud.
        $query = $this->getDb()
                      ->getQuery(true)
                      ->select($this->getPk() . ', parent_id, level, lft, rgt')
                      ->from($this->getTable())
                      ->where($k . ' = ' . (int)$id);

        if ($where) {
            $query->where($where);
        }

        $row = $this->getDb()
                    ->setQuery($query, 0, 1)
                    ->loadObject();

        // On contrôle la ligne retournée.
        if (empty($row)) {
            throw new \UnexpectedValueException(sprintf('%s::getNode(%d, %s, %s) failed. SQL: %s.', get_class($this), $id, $key, $where, (string)$query));
        }

        // On effectue des calculs.
        $row->numChildren = (int)($row->rgt - $row->lft - 1) / 2;
        $row->width       = (int)$row->rgt - $row->lft + 1;

        return $row;
    }

    /**
     * Méthode pour récupérer les données nécessaires pour créer l'espace dans l'arbre pour
     * positionner un noeud et ses enfants. L'objet de données retourné inclut les conditions
     * pour les clauses SQL WHERE pour la mise à jour des valeurs "lft" et "rgt".
     *
     * @param   object  $referenceNode   Un objet noeud avec au moins "lft" et "rgt" avec lesquellesA node object with at least a 'lft' and 'rgt' with
     *                                   on va créer l'espace autour du noeud.
     * @param   integer $nodeWidth       La taille du noeud.
     * @param   string  $position        La position relative au noeud de référence.
     *
     * @return  object|bool   Un objet de données ou false en cas d'échec.
     */
    protected function getTreeRepositionData($referenceNode, $nodeWidth, $position = 'before') {

        // On s'assure d'avoir un noeud avec au moins les ids left et right.
        if (!is_object($referenceNode) || !(isset($referenceNode->lft) && isset($referenceNode->rgt))) {
            return false;
        }

        // Un noeud valide ne pas avoir une taille inférieure à 2.
        if ($nodeWidth < 2) {
            return false;
        }

        $k    = $this->getPk();
        $data = new \stdClass;

        // On effectue le calcul et on construit l'objet de données en prenant pour référence le positionnement.
        switch ($position) {
            case 'first-child':
                $data->left_where  = 'lft > ' . $referenceNode->lft;
                $data->right_where = 'rgt >= ' . $referenceNode->lft;

                $data->new_lft       = $referenceNode->lft + 1;
                $data->new_rgt       = $referenceNode->lft + $nodeWidth;
                $data->new_parent_id = $referenceNode->$k;
                $data->new_level     = $referenceNode->level + 1;
                break;

            case 'last-child':
                $data->left_where  = 'lft > ' . ($referenceNode->rgt);
                $data->right_where = 'rgt >= ' . ($referenceNode->rgt);

                $data->new_lft       = $referenceNode->rgt;
                $data->new_rgt       = $referenceNode->rgt + $nodeWidth - 1;
                $data->new_parent_id = $referenceNode->$k;
                $data->new_level     = $referenceNode->level + 1;
                break;

            case 'before':
                $data->left_where  = 'lft >= ' . $referenceNode->lft;
                $data->right_where = 'rgt >= ' . $referenceNode->lft;

                $data->new_lft       = $referenceNode->lft;
                $data->new_rgt       = $referenceNode->lft + $nodeWidth - 1;
                $data->new_parent_id = $referenceNode->parent_id;
                $data->new_level     = $referenceNode->level;
                break;

            default:
            case 'after':
                $data->left_where  = 'lft > ' . $referenceNode->rgt;
                $data->right_where = 'rgt > ' . $referenceNode->rgt;

                $data->new_lft       = $referenceNode->rgt + 1;
                $data->new_rgt       = $referenceNode->rgt + $nodeWidth;
                $data->new_parent_id = $referenceNode->parent_id;
                $data->new_level     = $referenceNode->level;
                break;
        }

        return $data;
    }

}
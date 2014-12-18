<?php
/**
 * Part of the ETD Framework Table Package
 *
 * @copyright   Copyright (C) 2015 ETD Solutions, SARL Etudoo. Tous droits réservés.
 * @license     Apache License 2.0; see LICENSE
 * @author      ETD Solutions http://etd-solutions.com
 */

namespace EtdSolutions\Table;

use DumpableInterface;
use EtdSolutions\Application\Web;
use Joomla\Data\DataObject;
use Joomla\Language\Text;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * Représentation d'une table dans la base de données.
 */
abstract class Table extends DataObject {

    /**
     * @var string Nom du table.
     */
    protected $name;

    /**
     * @var string Nom de la table dans la BDD.
     */
    protected $table = '';

    /**
     * @var string Nom de la clé primaire dans la BDD.
     */
    protected $pk = '';

    /**
     * @var array Les erreurs survenues dans le table.
     */
    protected $errors = array();

    /**
     * @var bool Indique si la table est bloquée.
     */
    protected $locked = false;

    /**
     * @var  Table  Les instances des tables.
     */
    private static $instances;

    /**
     * Constructeur pour définir le nom de la table et la clé primaire.
     *
     * @param   string $table Nom de la table à modéliser.
     * @param   mixed  $pk    Nom de la clé primaire.
     *
     * @throws \InvalidArgumentException
     */
    public function __construct($table, $pk = 'id') {

        if (empty($table)) {
            throw new \InvalidArgumentException("Table name is empty");
        }

        // Nom de la table.
        $this->table = $table;

        // Nom de la clé primaire.
        $this->pk = $pk;

        // On initialise les propriétés du Table.
        $fields = $this->getFields();

        if ($fields) {
            foreach ($fields as $name) {
                // On ajoute le champ s'il n'est pas déjà présent.
                if (!isset($this->$name)) {
                    $this->setProperty($name, null);
                }
            }
        }

    }

    /**
     * Méthode pour récupérer une instance d'un table, la créant si besoin.
     *
     * @param   string $name Le nom de la classe
     *
     * @return  Table  L'instance.
     *
     * @throws   \RuntimeException
     */
    public static function getInstance($name) {

        $name  = ucfirst($name);
        $store = md5($name);

        if (empty(self::$instances[$store])) {

            // On définit la liste des espaces de noms dans laquelle le modèle peut se trouver.
            $namespaces = array(
                Web::getInstance()
                   ->get('app_namespace'),
                '\\EtdSolutions'
            );

            $className = "";

            // On cherche le modèle dans ces espaces de nom.
            foreach ($namespaces as $namespace) {

                $className = $namespace . '\\Table\\' . $name . 'Table';

                // Si on a trouvé la classe, on arrête.
                if (class_exists($className)) {
                    break;
                }

            }
            // On vérifie que l'on a bien une classe valide.
            if (!class_exists($className)) {
                throw new \RuntimeException("Unable find table " . $name, 500);
            }

            self::$instances[$store] = new $className();
        }

        return self::$instances[$store];
    }

    /**
     * Renvoi les colonnes de la table dans la base de données.
     * Doit être définit manuellement dans chaque instance.
     *
     * @return  array Un tableau des champs disponibles dans la table.
     */
    abstract public function getFields();

    /**
     * @return string Le nom du table dans la base de données.
     */
    public function getTable() {

        return $this->table;
    }

    /**
     * @return string Le nom de la clé primaire dans la base de données.
     */
    public function getPk() {

        return $this->pk;
    }

    /**
     * Méthode pour charger une ligne dans la base de données à partir de la clé primaire et la relier
     * aux propriétés de l'instance Table.
     *
     * @param   mixed $pk La clé primaire avec laquelle charger la ligne, ou un tableau de champs à comparer avec la base de données.
     *
     * @return  boolean  True si succès, false si la ligne n'a pas été trouvée.
     *
     * @throws  \InvalidArgumentException
     */
    public function load($pk = null) {

        // Si aucune clé primaire n'est donné, on prend celle de l'instance.
        if (is_null($pk)) {
            $pk = $this->getProperty($this->pk);
        }

        // Si la clé primaire est vide, on ne charge rien.
        if (empty($pk)) {
            return false;
        }

        // On récupère la base de données.
        $db = Web::getInstance()
                 ->getDb();

        // On initialise la requête.
        $query = $db->getQuery(true)
                    ->select('*')
                    ->from($this->table);

        if (is_array($pk)) {
            foreach ($pk as $k => $v) {
                $query->where($db->quoteName($k) . " = " . $db->quote($v));
            }
        } else {
            $query->where($db->quoteName($this->pk) . " = " . $db->quote($pk));
        }

        $db->setQuery($query);

        // On charge la ligne.
        $row = $db->loadAssoc();

        // On contrôle que l'on a bien un résultat.
        if (empty($row)) {
            $this->addError(Text::_('APP_ERROR_TABLE_EMPTY_ROW'));

            return false;
        }

        // On relie la ligne avec le table.
        $this->bind($row);

        return true;
    }

    public function bind($source, $updateNulls = true, $ignore = array()) {

        // On s'assure que la source est un tableau.
        $source = (array)$source;

        // On ne garde que les données liables avec le tableau.
        $source = array_intersect_key($source, (array)$this->dump(0));

        // On supprime les champs ignorés.
        $source = array_diff_key($source, array_fill_keys($ignore, null));

        return parent::bind($source, $updateNulls);
    }

    /**
     * Méthode pour faire des contrôle de sécurité sur les propriétés de l'instance Table
     * pour s'assurer qu'elles sont sûres avant leur stockage dans la base de données.
     *
     * @return  boolean  True si l'instance est saine et bonne à être stockée en base.
     */
    public function check() {

        return true;
    }

    /**
     * Méthod pour stocker une ligne dans la base de données avec les propriétés du Table.
     * Si la clé primaire est définit, la ligne avec cette clé primaire sera mise à jour.
     * S'il n'y a pas de clé primaire, une nouvelle ligne sera insérée et la clé primaire
     * du Table sera mise à jour.
     *
     * @param   boolean $updateNulls True pour mettre à jour les champs même s'ils sont null.
     *
     * @return  boolean  True en cas de succès.
     */
    public function store($updateNulls = false) {

        $db = Web::getInstance()
                 ->getDb();

        // On récupère les propriétés.
        $properties = $this->dump(0);

        // Si une clé primaire existe on met à jour l'objet, sinon on l'insert.
        if ($this->hasPrimaryKey()) {
            $result = $db->updateObject($this->table, $properties, $this->pk, $updateNulls);
        } else {
            $result = $db->insertObject($this->table, $properties, $this->pk);

            // On met à jour la nouvelle clé primaire dans le table.
            $this->setProperty($this->pk, $properties->{$this->pk});
        }

        return $result;
    }

    /**
     * Méthode pour mettre à disposition un raccourci pour relier, contrôler et stocker une
     * instance Table dans le table de la base de données.
     *
     * @param   mixed $data Un tableau associatif ou un objet à relier à l'instance Table.
     *
     * @return  boolean  True en cas de succès.
     */
    public function save($data) {

        // On essaye de relier la source à l'instance.
        if (!$this->bind($data)) {
            return false;
        }

        // On lance les contrôles de securité sur l'instance et on vérifie que tout est bon avant le stockage en base.
        if (!$this->check()) {
            return false;
        }

        // On essaye de stocker les propriétés en base.
        if (!$this->store()) {
            return false;
        }

        // On nettoie les erreurs.
        $this->clearErrors();

        return true;
    }

    /**
     * Méthode pour supprimer une ligne de la base de données grâce à une clé primaire.
     *
     * @param   mixed $pk Une clé primaire à supprimer. Optionnelle : si non définit la valeur de l'instance sera utilisée.
     *
     * @return  boolean  True en cas de succès.
     *
     * @throws  \UnexpectedValueException
     */
    public function delete($pk = null) {

        if (is_null($pk)) {
            $pk = $this->getProperty($this->pk);
        }

        $db = $this->getDb();

        // On supprime la ligne.
        $query = $db->getQuery(true)
                    ->delete($this->table);
        $query->where($db->quoteName($this->pk) . ' = ' . $db->quote($pk));

        $db->setQuery($query);

        $db->execute();

        $this->clearErrors();

        return true;
    }

    /**
     * Méthode pour définir l'état de publication d'une ligne ou d'une liste de lignes.
     *
     * @param   mixed $pks        Un tableau optionnel des clés primaires à modifier.
     *                            Si non définit, on prend la valeur de l'instance.
     * @param   int   $state      L'état de publication. eg. [0 = dépublié, 1 = publié]
     *
     * @return  bool  True en cas de succès, false sinon.
     */
    public function publish($pks = null, $state = 1) {

        // On initialise les variables.
        $pks    = (array)$pks;
        $state  = (int)$state;
        $fields = $this->getFields();
        $field  = null;

        // On détermine le bon champ.
        if (in_array('published', $fields)) {
            $field = 'published';
        } elseif (in_array('state', $fields)) {
            $field = 'state';
        } else {
            $this->addError(Text::_('APP_ERROR_TABLE_NO_PUBLISHED_FIELD'));

            return false;
        }

        // S'il n'y a pas de clés primaires de défini on regarde si on en a une dans l'instance.
        if (empty($pks)) {
            $pks = array($this->getProperty($this->getPk()));
        }

        $db = $this->getDb();

        $db->setQuery($db->getQuery(true)
                         ->update($this->getTable())
                         ->set($field . " = " . $state)
                         ->where($this->getPk() . " IN (" . implode(",", $pks) . ")"));

        $db->execute();

        // On met à jour l'instance si besoin.
        if (in_array($this->getProperty($this->getPk()), $pks)) {
            $this->setProperty($field, $state);
        }

        $this->clearErrors();

        return true;
    }

    /**
     * Méthode pour compacter les valeurs d'ordre des lignes dans un groupe de lignes définit
     * oar la clause WHERE.
     *
     * @param   string $where La clause WHERE pour limiter la sélection.
     *
     * @return  mixed  Boolean  True en cas de sucès.
     *
     * @throws  \UnexpectedValueException
     */
    public function reorder($where = '') {

        // If there is no ordering field set an error and return false.
        if (!in_array('ordering', $this->getFields())) {
            throw new \UnexpectedValueException(sprintf('%s does not support ordering.', get_class($this)));
        }

        $db = $this->getDb();

        // Get the primary keys and ordering values for the selection.
        $query = $db->getQuery(true)
                    ->select($db->quoteName($this->getPk()) . ', ordering')
                    ->from($this->getTable())
                    ->where('ordering >= 0')
                    ->order('ordering');

        // Setup the extra where and ordering clause data.
        if ($where) {
            $query->where($where);
        }

        $db->setQuery($query);
        $rows = $db->loadObjectList();

        // Compact the ordering values.
        foreach ($rows as $i => $row) {
            // Make sure the ordering is a positive integer.
            if ($row->ordering >= 0) {
                // Only update rows that are necessary.
                if ($row->ordering != $i + 1) {
                    // Update the row ordering field.
                    $query->clear()
                          ->update($this->getTable())
                          ->set('ordering = ' . ($i + 1))
                          ->where($db->quoteName($this->getPk()) . " = " . $db->quote($row->{$this->getPk()}));
                    $db->setQuery($query);
                    $db->execute();
                }
            }
        }

        return true;
    }

    /**
     * Méthode pour déplacer une ligne dans la séquence d'ordre d'un groupe de lignes définit par une clause WHERE.
     * Les nombres négatifs déplacer la ligne vers le haut et un nombre positif la déplacer vers le bas.
     *
     * @param   integer $delta La direction et la magnitude dans lesquelles déplacer la ligne.
     * @param   string  $where La clause WHERE pour limiter la sélection de lignes.
     *
     * @return  mixed    Boolean  True en cas de succès
     *
     * @throws  \UnexpectedValueException
     */
    public function move($delta, $where = '') {

        // If there is no ordering field set an error and return false.
        if (!in_array('ordering', $this->getFields())) {
            throw new \UnexpectedValueException(sprintf('%s does not support ordering.', get_class($this)));
        }

        // If the change is none, do nothing.
        if (empty($delta)) {
            return true;
        }

        $db    = $this->getDb();
        $row   = null;
        $query = $db->getQuery(true);

        // Select the primary key and ordering values from the table.
        $query->select($this->getPk() . ', ordering')
              ->from($this->getTable());

        // If the movement delta is negative move the row up.
        if ($delta < 0) {
            $query->where('ordering < ' . (int)$this->getProperty('ordering'))
                  ->order('ordering DESC');
        } // If the movement delta is positive move the row down.
        elseif ($delta > 0) {
            $query->where('ordering > ' . (int)$this->getProperty('ordering'))
                  ->order('ordering ASC');
        }

        // Add the custom WHERE clause if set.
        if ($where) {
            $query->where($where);
        }

        // Select the first row with the criteria.
        $db->setQuery($query, 0, 1);
        $row = $db->loadObject();

        // If a row is found, move the item.
        if (!empty($row)) {
            // Update the ordering field for this instance to the row's ordering value.
            $query->clear()
                  ->update($this->getTable())
                  ->set('ordering = ' . (int)$row->ordering)
                  ->where($db->quoteName($this->getPk()) . "=" . $db->quote($this->getProperty($this->getPk())));

            $db->setQuery($query);
            $db->execute();

            // Update the ordering field for the row to this instance's ordering value.
            $query->clear()
                  ->update($this->getTable())
                  ->set('ordering = ' . (int)$this->getProperty('ordering'))
                  ->where($db->quoteName($this->getPk()) . "=" . $db->quote($row->{$this->getPk()}));
            $db->setQuery($query);
            $db->execute();

            // Update the instance value.
            $this->setProperty('ordering', $row->ordering);
        } else {
            // Update the ordering field for this instance.
            $query->clear()
                  ->update($this->getTable())
                  ->set('ordering = ' . (int)$this->getProperty('ordering'))
                  ->where($db->quoteName($this->getPk()) . "=" . $db->quote($this->getProperty($this->getPk())));
            $db->setQuery($query);
            $db->execute();
        }

        return true;
    }

    /**
     * Méthode pour effacer toutes les valeurs des propriétés de la classe.
     * La clé primaire sera ignorée.
     *
     * @return  void
     */
    public function clear() {

        foreach ($this->getFields() as $k) {
            if ($k != $this->getPk()) {
                $this->setProperty($k, null);
            }
        }

        $this->clearErrors();
    }

    /**
     * Contrôle que la clé primaire a été définit.
     *
     * @return  boolean  True si la clé primaire est définit.
     */
    public function hasPrimaryKey() {

        $pk = $this->getProperty($this->pk);

        return !empty($pk);
    }

    /**
     * Méthode pour stocker une erreur.
     *
     * @param $error
     *
     * @return $this
     */
    public function addError($error) {

        array_push($this->errors, $error);

        return $this;
    }

    /**
     * Donne les erreurs survenues dans le table.
     *
     * @return array
     */
    public function getErrors() {

        return $this->errors;
    }

    /**
     * @return string La première erreur.
     */
    public function getError() {

        return count($this->errors) ? $this->errors[0] : false;
    }

    /**
     * Supprime toutes les erreurs.
     */
    public function clearErrors() {

        $this->errors = array();
    }

    /**
     * Méthode pour savoir si le table a des erreurs.
     *
     * @return bool True s'il y a des erreurs.
     */
    public function hasError() {

        return (count($this->errors) > 0);
    }

    /**
     * Méthode pour verrouiller une table dans la base.
     *
     * @return  boolean  True en cas de succès.
     *
     * @throws  \RuntimeException
     */
    protected function lock() {

        $this->getDb()
             ->lockTable($this->getTable());
        $this->locked = true;

        return true;
    }

    /**
     * Method to unlock the database table for writing.
     *
     * @return  boolean  True on success.
     *
     * @since   11.1
     */
    protected function unlock() {

        $this->getDb()
             ->unlockTables();
        $this->locked = false;

        return true;
    }

    /**
     * Méthode pour renvoyer l'objet global de base de données.
     *
     * @return \Joomla\Database\DatabaseDriver
     */
    protected function getDb() {

        return Web::getInstance()
                  ->getDb();
    }

}
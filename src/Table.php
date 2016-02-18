<?php
/**
 * Part of the ETD Framework Table Package
 *
 * @copyright   Copyright (C) 2015 ETD Solutions, SARL Etudoo. Tous droits réservés.
 * @license     Apache License 2.0; see LICENSE
 * @author      ETD Solutions http://etd-solutions.com
 */

namespace EtdSolutions\Table;

use EtdSolutions\Language\LanguageFactory;

use Joomla\Data\DataObject;
use Joomla\Database\DatabaseDriver;
use Joomla\DI\ContainerAwareInterface;
use Joomla\DI\ContainerAwareTrait;
use Joomla\String\StringHelper;

/**
 * Représentation d'une table dans la base de données.
 */
abstract class Table extends DataObject implements ContainerAwareInterface {

    use ContainerAwareTrait;

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
     * @var DatabaseDriver L'objet DatabaseDriver
     */
    protected $db;

    /**
     * Constructeur pour définir le nom de la table et la clé primaire.
     *
     * @param   string         $table Nom de la table à modéliser.
     * @param   mixed          $pk    Nom de la clé primaire.
     * @param   DatabaseDriver $db L'objet DatabaseDriver.
     *
     * @throws \InvalidArgumentException
     */
    public function __construct($table, $pk = 'id', DatabaseDriver $db) {

        if (empty($table)) {
            throw new \InvalidArgumentException("Table name is empty");
        }

        // Nom de la table.
        $this->table = $table;

        // Nom de la clé primaire.
        $this->pk = $pk;

        // DatabaseDriver
        $this->db = $db;

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

        $text = (new LanguageFactory)->getText();

        // Si aucune clé primaire n'est donné, on prend celle de l'instance.
        if (is_null($pk)) {
            $pk = $this->getProperty($this->pk);
        }

        // Si la clé primaire est vide, on ne charge rien.
        if (empty($pk)) {
            return false;
        };

        // On initialise la requête.
        $query = $this->db->getQuery(true)
                    ->select('*')
                    ->from($this->table);

        if (is_array($pk)) {
            foreach ($pk as $k => $v) {
                $query->where($this->db->quoteName($k) . " = " . $this->db->quote($v));
            }
        } else {
            $query->where($this->db->quoteName($this->pk) . " = " . $this->db->quote($pk));
        }

        $this->db->setQuery($query);

        // On charge la ligne.
        $row = $this->db->loadAssoc();

        // On contrôle que l'on a bien un résultat.
        if (empty($row)) {
            $this->addError($text->translate('APP_ERROR_TABLE_EMPTY_ROW'));

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

        // Bind the properties.
        foreach ($source as $property => $value) {

            // Check if the value is null and should be bound.
            if ($value === null && !$updateNulls) {
                continue;
            }

            // Array
            if (is_array($value)) {

                $old = $this->getProperty($property);

                if ($old !== null && (is_array($old) || is_object($old))) {

                    if (is_object($old)) {
                        $old = (array) $old;
                    }

                    $value = array_merge($old, $value);
                }

            }

            // Set the property.
            $this->setProperty($property, $value);
        }

        return $this;
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

        // On récupère les propriétés.
        $properties = $this->dump(0);

        // Si une clé primaire existe on met à jour l'objet, sinon on l'insert.
        if ($this->hasPrimaryKey()) {
            $result = $this->db->updateObject($this->table, $properties, $this->pk, $updateNulls);
        } else {
            $result = $this->db->insertObject($this->table, $properties, $this->pk);

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

        // On supprime la ligne.
        $query = $this->db->getQuery(true)
                    ->delete($this->table);
        $query->where($this->db->quoteName($this->pk) . ' = ' . $this->db->quote($pk));

        $this->db->setQuery($query);

        $this->db->execute();

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
        $text = (new LanguageFactory)->getText();
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
            $this->addError($text->translate('APP_ERROR_TABLE_NO_PUBLISHED_FIELD'));

            return false;
        }

        // S'il n'y a pas de clés primaires de défini on regarde si on en a une dans l'instance.
        if (empty($pks)) {
            $pks = array($this->getProperty($this->getPk()));
        }

        $this->db->setQuery($this->db->getQuery(true)
                         ->update($this->getTable())
                         ->set($field . " = " . $state)
                         ->where($this->getPk() . " IN (" . implode(",", $pks) . ")"));

        $this->db->execute();

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

        // Get the primary keys and ordering values for the selection.
        $query = $this->db->getQuery(true)
                    ->select($this->db->quoteName($this->getPk()) . ', ordering')
                    ->from($this->getTable())
                    ->where('ordering >= 0')
                    ->order('ordering');

        // Setup the extra where and ordering clause data.
        if ($where) {
            $query->where($where);
        }

        $this->db->setQuery($query);
        $rows = $this->db->loadObjectList();

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
                          ->where($this->db->quoteName($this->getPk()) . " = " . $this->db->quote($row->{$this->getPk()}));
                    $this->db->setQuery($query);
                    $this->db->execute();
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

        $row   = null;
        $query = $this->db->getQuery(true);

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
        $this->db->setQuery($query, 0, 1);
        $row = $this->db->loadObject();

        // If a row is found, move the item.
        if (!empty($row)) {
            // Update the ordering field for this instance to the row's ordering value.
            $query->clear()
                  ->update($this->getTable())
                  ->set('ordering = ' . (int)$row->ordering)
                  ->where($this->db->quoteName($this->getPk()) . "=" . $this->db->quote($this->getProperty($this->getPk())));

            $this->db->setQuery($query);
            $this->db->execute();

            // Update the ordering field for the row to this instance's ordering value.
            $query->clear()
                  ->update($this->getTable())
                  ->set('ordering = ' . (int)$this->getProperty('ordering'))
                  ->where($this->db->quoteName($this->getPk()) . "=" . $this->db->quote($row->{$this->getPk()}));
            $this->db->setQuery($query);
            $this->db->execute();

            // Update the instance value.
            $this->setProperty('ordering', $row->ordering);
        } else {
            // Update the ordering field for this instance.
            $query->clear()
                  ->update($this->getTable())
                  ->set('ordering = ' . (int)$this->getProperty('ordering'))
                  ->where($this->db->quoteName($this->getPk()) . "=" . $this->db->quote($this->getProperty($this->getPk())));
            $this->db->setQuery($query);
            $this->db->execute();
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
     * @return  boolean  True si la clé primaire est définie.
     */
    public function hasPrimaryKey() {

        $pks = (array) $this->pk;
        $ret = true;

        foreach($pks as $pk) {

            $value = $this->getProperty($pk);
            if (empty($value)) {
                return false;
            }

        }

        return $ret;

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

        $this->db->lockTable($this->getTable());
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

        $this->db->unlockTables();
        $this->locked = false;

        return true;
    }

    /**
     * This method processes a string and replaces all accented UTF-8 characters by unaccented
     * ASCII-7 "equivalents", whitespaces are replaced by hyphens and the string is lowercase.
     *
     * @param   string  $string  String to process
     *
     * @return  string  Processed string
     *
     * @since   1.0
     */
    public static function stringURLSafe($string) {

        // Remove any '-' from the string since they will be used as concatenaters
        $str = str_replace('-', ' ', $string);

        $factory = new LanguageFactory();
        $str = $factory->getLanguage()->transliterate($str);

        // Trim white spaces at beginning and end of alias and make lowercase
        $str = trim(StringHelper::strtolower($str));

        // Remove any duplicate whitespace, and ensure all characters are alphanumeric
        $str = preg_replace('/(\s|[^A-Za-z0-9\-])+/', '-', $str);

        // Trim dashes at beginning and end of alias
        $str = trim($str, '-');

        return $str;
    }

}
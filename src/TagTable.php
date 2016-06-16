<?php
/**
 * @package     EtdInterfaces
 *
 * @version     0.0.1
 * @copyright   Copyright (C) 2014 ETD Solutions, SARL Etudoo. Tous droits réservés.
 * @license     http://etd-solutions.com/LICENSE
 * @author      ETD Solutions http://etd-solutions.com
 */

namespace EtdSolutions\Table;

use Joomla\Date\Date;
use Joomla\String\StringHelper;

class TagTable extends NestedTable {

    public function __construct(\Joomla\Database\DatabaseDriver $db) {

        parent::__construct('#__tags', 'id', $db);
    }

    /**
     * Renvoi les colonnes de la table dans la base de données.
     * Doit être définit manuellement dans chaque instance.
     *
     * @return  array Un tableau des champs disponibles dans la table.
     */
    public function getFields() {

        return array(
            'id',
            'parent_id',
            'lft',
            'rgt',
            'level',
            'title',
            'path',
            'alias',
            'description',
            'published',
            'checked_out',
            'checked_out_time',
            'params',
            'created',
            'created_by',
            'modified',
            'modified_by'
        );
    }

    public function bind($source, $updateNulls = true, $ignore = array()) {

        if (array_key_exists('params', $source) && is_array($source['params'])) {
            $source['params'] = json_encode($source['params']);
        }

        return parent::bind($source, $updateNulls, $ignore);
    }

    public function check() {

        // Alias
        $this->setProperty('alias', self::stringURLSafe($this->getProperty('alias')));

        // Si l'alias est vide, on le crée depuis le nom.
        if (empty($this->alias)) {
            $this->setProperty('alias', self::stringURLSafe($this->getProperty('name')));
        }

        // On contrôle que l'alias est unique.
        $table = new TagTable($this->db);
        $table->setContainer($this->getContainer());
        $alias = $this->getProperty('alias');
        while ($table->load(['alias' => $alias])) {
            $alias = StringHelper::increment($alias, 'dash');
        }

        $this->setProperty('alias', $alias);

        // Date actuelle.
        $date = new Date();
        $now = $date->format($this->db->getDateFormat());

        // Date de création.
        $created = $this->getProperty('created');
        $id = $this->getProperty('id');
        if (empty($id) || empty($created) || $created == $this->db->getNullDate()) {
            $this->setProperty('created', $now);
        }

        // Date de modification.
        if (!empty($id)) {
            $this->setProperty('modified', $now);
        }

        return parent::check();

    }

    public function delete($pk = null, $children = true, $where = null) {

        $k  = $this->getPk();
        $pk = (is_null($pk)) ? $this->getProperty($k) : $pk;

        if (parent::delete($pk, $children, $where)) {

            // On supprime les associations des tags.
            $this->db->setQuery(
                $this->db->getQuery(true)
                    ->delete('#__tags_map')
                    ->where('tag_id = ' . (int) $pk)
            )->execute();

            return true;

        }

        return false;
    }

}
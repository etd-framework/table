<?php
/**
 * Part of the ETD Framework Table Package
 *
 * @copyright   Copyright (C) 2015 ETD Solutions, SARL Etudoo. Tous droits réservés.
 * @license     Apache License 2.0; see LICENSE
 * @author      ETD Solutions http://etd-solutions.com
 */

namespace EtdSolutions\Table;

use Joomla\Database\DatabaseDriver;

class AclTable extends Table {

    public function __construct(DatabaseDriver $db) {

        parent::__construct('#__acl', 'id', $db);
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
            'resource',
            'rules'
        );
    }

    public function bind($properties, $updateNulls = true, $ignore = array()) {

        // On convertit le tableau de paramètres en JSON.
        if (array_key_exists('rules', $properties) && is_array($properties['rules'])) {
            $properties['rules'] = json_encode($properties['rules']);
        }

        return parent::bind($properties, $updateNulls, $ignore);
    }

    /**
     * Méthode pour charger des règles ACL en fonction de la ressource associée.
     *
     * @param  string $resource Le nom de la ressource.
     * @return bool True si succès, false sinon.
     */
    public function loadByResource($resource) {

        return parent::load([
            'resource' => $resource
        ]);
    }

}
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
use Joomla\Database\DatabaseDriver;
use Joomla\Date\Date;
use Joomla\Registry\Registry;

class UserTable extends Table {

    public function __construct(DatabaseDriver $db) {

        parent::__construct('#__users', 'id', $db);
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
            'company_id',
            'name',
            'username',
            'email',
            'password',
            'block',
            'sendEmail',
            'registerDate',
            'lastvisitDate',
            'activation',
            'params',
            'lastResetTime',
            'resetCount',
            'otpKey',
            'otep',
            'requireReset',
            'profile'
        );
    }

    public function bind($properties, $updateNulls = true, $ignore = array()) {

        // On convertit le tableau de paramètres en JSON.
        if (array_key_exists('params', $properties) && is_array($properties['params'])) {
            $registry = new Registry($properties['params']);
            $properties['params'] = $registry->toString();
        }

        return parent::bind($properties, $updateNulls, $ignore);
    }

    public function check() {

        // On contrôle que le nom d'utilisateur n'est pas plus long que 150 caractères.
        $username = $this->getProperty('username');

        if (strlen($username) > 150) {
            $username = substr($username, 0, 150);
            $this->setProperty('username', $username);
        }

        return true;
    }

    public function setLastVisit($date = null, $pk = null) {

        // Pas de clé primaire, on prend celle de l'instance.
        if (is_null($pk)) {
            $pk = $this->getProperty($this->pk);
        }

        // Si la clé primaire est vide, on ne change rien.
        if (empty($pk)) {
            return false;
        }

        // On formate la date suivant le type.
        if (is_numeric($date)) { // Timestamp UNIX
            $date = new Date($date);
        } elseif (is_string($date)) { // Une chaine formatée.
            $date = new Date($date);
        } elseif (is_null($date)) { // Pas de date, on prend celle de maintenant
            $date = new Date();
        } elseif (!($date instanceof Date)) { // Si en dernier lieu, on a pas passé un objet Date, le paramètre est invalide.
            throw new \InvalidArgumentException('Bad date parameter.');
        }

        // On formate la date.
        $formated_date = $date->format($this->db->getDateFormat());

        // On met à jour la ligne.
        $this->db->setQuery($this->db->getQuery(true)
                         ->update($this->table)
                         ->set($this->db->quoteName('lastvisitDate') . " = " . $this->db->quote($formated_date))
                         ->where($this->db->quoteName($this->pk) . ' = ' . $this->db->quote($pk)));

        $this->db->execute();

        return true;

    }

    public function load($pk = null) {

        $result = parent::load($pk);

        if ($result) {

            // On récupère les données du profil.
            $query = $this->db->getQuery(true)
                        ->select('a.profile_key, a.profile_value')
                        ->from('#__user_profiles AS a')
                        ->where('a.user_id = ' . (int)$this->getProperty($this->getPk()));

            $data = $this->db->setQuery($query)
                          ->loadObjectList();

            $profile = new \stdClass();
            foreach($data as $d) {
                $profile->{$d->profile_key} = $d->profile_value;
            }

            // On relie la ligne avec le table.
            $this->bind(array('profile' => $profile));

        }

        return $result;
    }

    public function store($updateNulls = false) {

        // On vérifie que l'identifiant est unique.
        $table = new UserTable($this->db);
        $table->setContainer($this->getContainer());
        if ($table->load(array('username' => $this->getProperty('username'))) && ($table->id != $this->getProperty('id') || $this->getProperty('id') == 0)) {
            $text = (new LanguageFactory)->getText();
            $this->addError($text->sprintf('APP_ERROR_NOT_UNIQUE_USERNAME', $this->getProperty('username')));
            return false;
        }

        // On récupère les propriétés.
        $properties = $this->dump();

        // On sépare les données de profil.
        $hasProfile = property_exists($properties, 'profile');
        if ($hasProfile) {
            $profile = $properties->profile;
            unset($properties->profile);
        }

        // Si une clé primaire existe on met à jour l'objet, sinon on l'insert.
        if ($this->hasPrimaryKey()) {
            $result = $this->db->updateObject($this->table, $properties, $this->pk, $updateNulls);

            // On traite le profil.
            if ($hasProfile) {

                // On supprime toutes les clés dans la table.
                $this->db->setQuery('DELETE FROM #__user_profiles WHERE user_id = ' . (int)$properties->{$this->pk})
                   ->execute();

            }

        } else {
            $result = $this->db->insertObject($this->table, $properties, $this->pk);

            // On met à jour la nouvelle clé primaire dans le table.
            $this->setProperty($this->pk, $properties->{$this->pk});
        }

        if ($hasProfile) {

            $tuples = array();

            foreach ($profile as $k => $v) {
                $tuples[] = $this->db->quote($this->getProperty($this->getPk())) . ", " . $this->db->quote($k) . ", " . $this->db->quote($v);
            }

            if (!empty($tuples)) {
                $query = $this->db->getQuery(true)
                    ->insert('#__user_profiles')
                    ->columns(array(
                        'user_id',
                        'profile_key',
                        'profile_value'
                    ))
                    ->values($tuples);

                $this->db->setQuery($query)
                    ->execute();
            }

        }

        return $result;
    }

    /**
     * Méthode pour définir l'état d'activation d'une ligne ou d'une liste de lignes.
     *
     * @param   mixed $pks        Un tableau optionnel des clés primaires à modifier.
     *                            Si non définit, on prend la valeur de l'instance.
     * @param   int   $state      L'état de publication. eg. [0 = dépublié, 1 = publié]
     *
     * @return  bool  True en cas de succès, false sinon.
     */
    public function block($pks = null, $state = 1) {

        // On initialise les variables.
        $pks    = (array)$pks;
        $state  = (int)$state;

        // S'il n'y a pas de clés primaires de défini on regarde si on en a une dans l'instance.
        if (empty($pks)) {
            $pks = array($this->getProperty($this->getPk()));
        }

        $this->db->setQuery($this->db->getQuery(true)
                                     ->update($this->getTable())
                                     ->set("block = " . $state)
                                     ->where($this->getPk() . " IN (" . implode(",", $pks) . ")"));

        $this->db->execute();

        // On met à jour l'instance si besoin.
        if (in_array($this->getProperty($this->getPk()), $pks)) {
            $this->setProperty("block", $state);
        }

        $this->clearErrors();

        return true;
    }

}
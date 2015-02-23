<?php
/**
 * Part of the ETD Framework Table Package
 *
 * @copyright   Copyright (C) 2015 ETD Solutions, SARL Etudoo. Tous droits réservés.
 * @license     Apache License 2.0; see LICENSE
 * @author      ETD Solutions http://etd-solutions.com
 */

namespace EtdSolutions\Table;

use EtdSolutions\Application\Web;
use EtdSolutions\User\User;
use Joomla\Crypt\Password\Simple;
use Joomla\Date\Date;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

class UserTable extends Table {

    public function __construct() {

        parent::__construct('#__users', 'id');
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

        // On génère le mot de passe crypté si besoin.
        if (array_key_exists('password', $properties) && !empty($properties['password']) && substr($properties['password'], 0, 4) != '$2a$' && substr($properties['password'], 0, 4) != '$2y$') {
            $simpleAuth             = new Simple();
            $properties['password'] = $simpleAuth->create($properties['password']);
        }

        // On convertit le tableau de paramètres en JSON.
        if (array_key_exists('params', $properties) && is_array($properties['params'])) {
            $registry             = new Registry($properties['params']);
            $properties['params'] = $registry->toString();
        }

        return parent::bind($properties, $updateNulls, $ignore);
    }

    public function check() {

        $pk = $this->getProperty($this->getPk());

        $db = Web::getInstance()
                 ->getDb();

        // Date actuelle.
        $date = new Date();
        $now  = $date->format($db->getDateFormat());

        // On regarde si c'est un nouvel utilisateur ou non.
        if (empty($pk)) {

            // On contrôle le mot de passe et on crée le mot de passe crypté si besoin.
            if (empty($this->password)) {
                $simpleAuth = new Simple();
                $this->setProperty('password', $simpleAuth->create(User::genRandomPassword()));
            }

            // On définit la date d'inscription.
            $this->setProperty('registerDate', $now);

        }

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

        // On récupère la base de données.
        $db = Web::getInstance()
                 ->getDb();

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
        $formated_date = $date->format($db->getDateFormat());

        // On met à jour la ligne.
        $db->setQuery($db->getQuery(true)
                         ->update($this->table)
                         ->set($db->quoteName('lastvisitDate') . " = " . $db->quote($formated_date))
                         ->where($db->quoteName($this->pk) . ' = ' . $db->quote($pk)));

        $db->execute();

        return true;

    }

    public function load($pk = null) {

        $result = parent::load($pk);

        if ($result) {

            // On récupère les données du profil.
            $db    = $this->getDb();
            $query = $db->getQuery(true)
                        ->select('a.profile_key, a.profile_value')
                        ->from('#__user_profiles AS a')
                        ->where('a.user_id = ' . (int)$this->getProperty($this->getPk()));

            $data = $db->setQuery($query)
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

        $db = $this->getDb();

        // On récupère les propriétés.
        $properties = $this->dump(0);

        // On sépare les données de profil.
        $hasProfile = property_exists($properties, 'profile');
        if ($hasProfile) {
            $profile = $properties->profile;
            unset($properties->profile);
        }

        // Si une clé primaire existe on met à jour l'objet, sinon on l'insert.
        if ($this->hasPrimaryKey()) {
            $result = $db->updateObject($this->table, $properties, $this->pk, $updateNulls);

            // On traite le profil.
            if ($hasProfile) {

                // On supprime toutes les clés dans la table.
                $db->setQuery('DELETE FROM #__user_profiles WHERE user_id = ' . (int)$properties->{$this->pk})
                   ->execute();

            }

        } else {
            $result = $db->insertObject($this->table, $properties, $this->pk);

            // On met à jour la nouvelle clé primaire dans le table.
            $this->setProperty($this->pk, $properties->{$this->pk});
        }

        if ($hasProfile) {

            $tuples = array();

            foreach (get_object_vars($profile) as $k => $v) {
                $tuples[] = array(
                    $this->getProperty($this->getPk()),
                    $k,
                    $v
                );
            }

            $query = $db->getQuery(true)
                        ->insert('#__user_profile')
                        ->columns(array(
                                'user_id',
                                'profile_key',
                                'profile_value'
                            ))
                        ->values($tuples);

            $db->setQuery($query)
               ->execute();

        }

        return $result;
    }

}
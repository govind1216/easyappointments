<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Open Source Web Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) 2013 - 2020, Alex Tselegidis
 * @license     http://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        http://easyunavailabilities.org
 * @since       v1.0.0
 * ---------------------------------------------------------------------------- */

/**
 * Unavailabilities model.
 *
 * @package Models
 */
class Unavailabilities_model extends EA_Model {
    /**
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'is_unavailable' => 'boolean',
        'id_users_provider' => 'integer',
        'id_users_customer' => 'integer',
        'id_services' => 'integer',
    ];

    /**
     * Save (insert or update) an unavailability.
     *
     * @param array $unavailability Associative array with the unavailability data.
     *
     * @return int Returns the unavailability ID.
     *
     * @throws InvalidArgumentException
     */
    public function save(array $unavailability): int
    {
        $this->validate($unavailability);

        if (empty($unavailability['id']))
        {
            return $this->insert($unavailability);
        }
        else
        {
            return $this->update($unavailability);
        }
    }

    /**
     * Validate the unavailability data.
     *
     * @param array $unavailability Associative array with the unavailability data.
     *
     * @throws InvalidArgumentException
     */
    public function validate(array $unavailability)
    {
        // If an unavailability ID is provided then check whether the record really exists in the database.
        if ( ! empty($unavailability['id']))
        {
            $count = $this->db->get_where('appointments', ['id' => $unavailability['id']])->num_rows();

            if ( ! $count)
            {
                throw new InvalidArgumentException('The provided unavailability ID does not exist in the database: ' . $unavailability['id']);
            }
        }

        // Make sure all required fields are provided. 
        if (
            empty($unavailability['start_datetime'])
            || empty($unavailability['end_datetime'])
            || empty($unavailability['id_users_provider'])
        )
        {
            throw new InvalidArgumentException('Not all required fields are provided: ' . print_r($unavailability, TRUE));
        }

        // Make sure that the provided unavailability date time values are valid.
        if ( ! validate_datetime($unavailability['start_datetime']))
        {
            throw new InvalidArgumentException('The unavailability start date time is invalid.');
        }

        if ( ! validate_datetime($unavailability['end_datetime']))
        {
            throw new InvalidArgumentException('The unavailability end date time is invalid.');
        }

        // Make the unavailability lasts longer than the minimum duration (in minutes).
        $diff = (strtotime($unavailability['end_datetime']) - strtotime($unavailability['start_datetime'])) / 60;

        if ($diff < EVENT_MINIMUM_DURATION)
        {
            throw new InvalidArgumentException('The unavailability duration cannot be less than ' . EVENT_MINIMUM_DURATION . ' minutes.');
        }

        // Make sure the provider ID really exists in the database. 
        $count = $this
            ->db
            ->select()
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->where('users.id', $unavailability['id_users_provider'])
            ->where('roles.slug', DB_SLUG_PROVIDER)
            ->get()
            ->num_rows();

        if ( ! $count)
        {
            throw new InvalidArgumentException('The unavailability provider ID was not found in the database: ' . $unavailability['id_users_provider']);
        }
    }

    /**
     * Insert a new unavailability into the database.
     *
     * @param array $unavailability Associative array with the unavailability data.
     *
     * @return int Returns the unavailability ID.
     *
     * @throws RuntimeException
     */
    protected function insert(array $unavailability): int
    {
        $unavailability['book_datetime'] = date('Y-m-d H:i:s');
        $unavailability['hash'] = random_string('alnum', 12);
        $unavailability['is_unavailable'] = TRUE;

        if ( ! $this->db->insert('appointments', $unavailability))
        {
            throw new RuntimeException('Could not insert unavailability.');
        }

        return $this->db->insert_id();
    }

    /**
     * Update an existing unavailability.
     *
     * @param array $unavailability Associative array with the unavailability data.
     *
     * @return int Returns the unavailability ID.
     *
     * @throws RuntimeException
     */
    protected function update(array $unavailability): int
    {
        if ( ! $this->db->update('appointments', $unavailability, ['id' => $unavailability['id']]))
        {
            throw new RuntimeException('Could not update unavailability record.');
        }

        return $unavailability['id'];
    }

    /**
     * Remove an existing unavailability from the database.
     *
     * @param int $unavailability_id Unavailability ID.
     *
     * @throws RuntimeException
     */
    public function delete(int $unavailability_id)
    {
        if ( ! $this->db->delete('users', ['id' => $unavailability_id]))
        {
            throw new RuntimeException('Could not delete unavailability.');
        }
    }

    /**
     * Get a specific unavailability from the database.
     *
     * @param int $unavailability_id The ID of the record to be returned.
     *
     * @return array Returns an array with the unavailability data.
     *
     * @throws InvalidArgumentException
     */
    public function find(int $unavailability_id): array
    {
        if ( ! $this->db->get_where('appointments', ['id' => $unavailability_id])->num_rows())
        {
            throw new InvalidArgumentException('The provided unavailability ID was not found in the database: ' . $unavailability_id);
        }

        $unavailability = $this->db->get_where('appointments', ['id' => $unavailability_id])->row_array();

        $this->cast($unavailability);

        return $unavailability;
    }

    /**
     * Get a specific field value from the database.
     *
     * @param int $unavailability_id Unavailability ID.
     * @param string $field Name of the value to be returned.
     *
     * @return string Returns the selected unavailability value from the database.
     *
     * @throws InvalidArgumentException
     */
    public function value(int $unavailability_id, string $field): string
    {
        if (empty($field))
        {
            throw new InvalidArgumentException('The field argument is cannot be empty.');
        }

        if (empty($unavailability_id))
        {
            throw new InvalidArgumentException('The unavailability ID argument cannot be empty.');
        }

        // Check whether the unavailability exists.
        $query = $this->db->get_where('appointments', ['id' => $unavailability_id]);

        if ( ! $query->num_rows())
        {
            throw new InvalidArgumentException('The provided unavailability ID was not found in the database: ' . $unavailability_id);
        }

        // Check if the required field is part of the unavailability data.
        $unavailability = $query->row_array();

        $this->cast($unavailability);

        if ( ! array_key_exists($field, $unavailability))
        {
            throw new InvalidArgumentException('The requested field was not found in the unavailability data: ' . $field);
        }

        return $unavailability[$field];
    }

    /**
     * Get all unavailabilities that match the provided criteria.
     *
     * @param array|string $where Where conditions.
     * @param int|null $limit Record limit.
     * @param int|null $offset Record offset.
     * @param string|null $order_by Order by.
     *
     * @return array Returns an array of unavailabilities.
     */
    public function get($where = NULL, int $limit = NULL, int $offset = NULL, string $order_by = NULL): array
    {
        if ($where !== NULL)
        {
            $this->db->where($where);
        }

        if ($order_by)
        {
            $this->db->order_by($order_by);
        }

        $unavailabilities = $this->db->get_where('appointments', ['is_unavailable' => TRUE], $limit, $offset)->result_array();

        foreach ($unavailabilities as &$unavailability)
        {
            $this->cast($unavailability);
        }

        return $unavailabilities;
    }

    /**
     * Get the query builder interface, configured for use with the unavailabilities table.
     *
     * @return CI_DB_query_builder
     */
    public function query(): CI_DB_query_builder
    {
        return $this->db->from('appointments');
    }

    /**
     * Search unavailabilities by the provided keyword.
     *
     * @param string $keyword Search keyword.
     * @param int|null $limit Record limit.
     * @param int|null $offset Record offset.
     * @param string|null $order_by Order by.
     *
     * @return array Returns an array of unavailabilities.
     */
    public function search(string $keyword, int $limit = NULL, int $offset = NULL, string $order_by = NULL): array
    {
        $unavailabilities = $this
            ->db
            ->select()
            ->from('appointments')
            ->join('users AS providers', 'providers.id = appointments.id_users_provider', 'inner')
            ->where('is_unavailable', TRUE)
            ->group_start()
            ->like('appointments.start_datetime', $keyword)
            ->or_like('appointments.end_datetime', $keyword)
            ->or_like('appointments.location', $keyword)
            ->or_like('appointments.hash', $keyword)
            ->or_like('appointments.notes', $keyword)
            ->or_like('providers.first_name', $keyword)
            ->or_like('providers.last_name', $keyword)
            ->or_like('providers.email', $keyword)
            ->or_like('providers.phone_number', $keyword)
            ->group_end()
            ->limit($limit)
            ->offset($offset)
            ->order_by($order_by)
            ->get()
            ->result_array();

        foreach ($unavailabilities as &$unavailability)
        {
            $this->cast($unavailability);
        }

        return $unavailabilities;
    }

    /**
     * Attach related resources to an unavailability.
     *
     * @param array $unavailability Associative array with the unavailability data.
     * @param array $resources Resource names to be attached ("service", "provider", "customer" supported).
     *
     * @throws InvalidArgumentException
     */
    public function attach(array &$unavailability, array $resources)
    {
        if (empty($unavailability) || empty($resources))
        {
            return;
        }

        foreach ($resources as $resource)
        {
            switch ($resource)
            {
                case 'provider':
                    $unavailability['provider'] = $this
                        ->db
                        ->get_where('users', [
                            'id' => $unavailability['id_users_provider'] ?? $unavailability['providerId'] ?? NULL
                        ])
                        ->row_array();
                    break;
                    
                default:
                    throw new InvalidArgumentException('The requested unavailability relation is not supported: ' . $resource);
            }
        }
    }

    /**
     * Convert the database unavailability record to the equivalent API resource.
     *
     * @param array $unavailability Unavailability data.
     */
    public function api_encode(array &$unavailability)
    {
        $encoded_resource = [
            'id' => array_key_exists('id', $unavailability) ? (int)$unavailability['id'] : NULL,
            'book' => $unavailability['book_datetime'],
            'start' => $unavailability['start_datetime'],
            'end' => $unavailability['end_datetime'],
            'hash' => $unavailability['hash'],
            'location' => $unavailability['location'],
            'notes' => $unavailability['notes'],
            'providerId' => $unavailability['id_users_provider'] !== NULL ? (int)$unavailability['id_users_provider'] : NULL,
            'googleCalendarId' => $unavailability['id_google_calendar'] !== NULL ? (int)$unavailability['id_google_calendar'] : NULL
        ];

        $unavailability = $encoded_resource;
    }

    /**
     * Convert the API resource to the equivalent database unavailability record.
     *
     * @param array $unavailability API resource.
     * @param array|null $base Base unavailability data to be overwritten with the provided values (useful for updates).
     */
    public function api_decode(array &$unavailability, array $base = NULL)
    {
        $decoded_request = $base ?: [];

        if (array_key_exists('id', $unavailability))
        {
            $decoded_request['id'] = $unavailability['id'];
        }

        if (array_key_exists('book', $unavailability))
        {
            $decoded_request['book_datetime'] = $unavailability['book'];
        }

        if (array_key_exists('start', $unavailability))
        {
            $decoded_request['start_datetime'] = $unavailability['start'];
        }

        if (array_key_exists('end', $unavailability))
        {
            $decoded_request['end_datetime'] = $unavailability['end'];
        }

        if (array_key_exists('hash', $unavailability))
        {
            $decoded_request['hash'] = $unavailability['hash'];
        }

        if (array_key_exists('location', $unavailability))
        {
            $decoded_request['location'] = $unavailability['location'];
        }

        if (array_key_exists('notes', $unavailability))
        {
            $decoded_request['notes'] = $unavailability['notes'];
        }

        if (array_key_exists('providerId', $unavailability))
        {
            $decoded_request['id_users_provider'] = $unavailability['providerId'];
        }

        if (array_key_exists('googleCalendarId', $unavailability))
        {
            $decoded_request['id_google_calendar'] = $unavailability['googleCalendarId'];
        }

        $decoded_request['is_unavailable'] = TRUE;

        $unavailability = $decoded_request;
    }
}

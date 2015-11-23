<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Open Source Web Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) 2013 - 2015, Alex Tselegidis
 * @license     http://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        http://easyappointments.org
 * @since       v1.0.0
 * ---------------------------------------------------------------------------- */

/**
 * Appointments Controller
 *
 * @package Controllers
 */
class Appointments extends CI_Controller {

    /**
     * Class Constructor
     */
	public function __construct() {
		parent::__construct();

		$this->load->library('session');
        $this->load->helper('installation');

        // Set user's selected language.
		if ($this->session->userdata('language')) {
			$this->config->set_item('language', $this->session->userdata('language'));
			$this->lang->load('translations', $this->session->userdata('language'));
		} else {
			$this->lang->load('translations', $this->config->item('language')); // default
		}
	}

    /**
     * Default callback method of the application.
     *
     * This method creates the appointment book wizard. If an appointment hash
     * is provided then it means that the customer followed the appointment
     * manage link that was send with the book success email.
     *
     * @param string $appointment_hash The db appointment hash of an existing
     * record.
     */
    public function index($appointment_hash = '') {
        if (!is_ea_installed()) {
            redirect('installation/index');
            return;
        }

        $this->load->model('appointments_model');
        $this->load->model('providers_model');
        $this->load->model('services_model');
        $this->load->model('customers_model');
        $this->load->model('settings_model');

        try {
            $available_services  = $this->services_model->get_available_services();
            $available_providers = $this->providers_model->get_available_providers();
            $company_name        = $this->settings_model->get_setting('company_name');

            // If an appointment hash is provided then it means that the customer
            // is trying to edit a registered appointment record.
            if ($appointment_hash !== ''){
                // Load the appointments data and enable the manage mode of the page.
                $manage_mode = TRUE;

                $results = $this->appointments_model->get_batch(array('hash' => $appointment_hash));

                if (count($results) === 0) {
                    // The requested appointment doesn't exist in the database. Display
                    // a message to the customer.
                    $view = array(
                        'message_title' => $this->lang->line('appointment_not_found'),
                        'message_text'  => $this->lang->line('appointment_does_not_exist_in_db'),
                        'message_icon'  => $this->config->item('base_url')
                                         . '/assets/img/error.png'
                    );
                    $this->load->view('appointments/message', $view);
                    return;
                }

                $appointment = $results[0];
                $provider = $this->providers_model->get_row($appointment['id_users_provider']);
                $customer = $this->customers_model->get_row($appointment['id_users_customer']);

            } else {
                // The customer is going to book a new appointment so there is no
                // need for the manage functionality to be initialized.
                $manage_mode        = FALSE;
                $appointment   = array();
                $provider      = array();
                $customer      = array();
            }

            $google_analytics_code = $this->settings_model->get_setting('google_analytics_code');

            // Load the book appointment view.
            $view = array (
                'available_services'    => $available_services,
                'available_providers'   => $available_providers,
                'company_name'          => $company_name,
                'manage_mode'           => $manage_mode,
                'appointment_data'      => $appointment,
                'provider_data'         => $provider,
                'customer_data'         => $customer,
                'google_analytics_code' => $google_analytics_code
            );

        } catch(Exception $exc) {
            $view['exceptions'][] = $exc;
        }

        $this->load->view('appointments/book', $view);
    }

    /**
     * Cancel an existing appointment.
     *
     * This method removes an appointment from the company's schedule.
     * In order for the appointment to be deleted, the hash string must
     * be provided. The customer can only cancel the appointment if the
     * edit time period is not over yet.
     *
     * @param string $appointment_hash This is used to distinguish the
     * appointment record.
     * @param string $_POST['cancel_reason'] The text that describes why
     * the customer cancelled the appointment.
     */
    public function cancel($appointment_hash) {
        try {
            $this->load->model('appointments_model');
            $this->load->model('providers_model');
            $this->load->model('customers_model');
            $this->load->model('services_model');
            $this->load->model('settings_model');

            // Check whether the appointment hash exists in the database.
            $records = $this->appointments_model->get_batch(array('hash' => $appointment_hash));
            if (count($records) == 0) {
                throw new Exception('No record matches the provided hash.');
            }

            $appointment = $records[0];
            $provider = $this->providers_model->get_row($appointment['id_users_provider']);
            $customer = $this->customers_model->get_row($appointment['id_users_customer']);
            $service = $this->services_model->get_row($appointment['id_services']);

            $company_settings = array(
                'company_name' => $this->settings_model->get_setting('company_name'),
                'company_email' => $this->settings_model->get_setting('company_email'),
                'company_link' => $this->settings_model->get_setting('company_link')
            );

            // :: DELETE APPOINTMENT RECORD FROM THE DATABASE.
            if (!$this->appointments_model->delete($appointment['id'])) {
                throw new Exception('Appointment could not be deleted from the database.');
            }

            // :: SYNC APPOINTMENT REMOVAL WITH GOOGLE CALENDAR
        	if ($appointment['id_google_calendar'] != NULL) {
                try {
                    $google_sync = $this->providers_model->get_setting('google_sync',
                            $appointment['id_users_provider']);

                    if ($google_sync == TRUE) {
                        $google_token = json_decode($this->providers_model
                                ->get_setting('google_token', $provider['id']));
                        $this->load->library('Google_Sync');
                        $this->google_sync->refresh_token($google_token->refresh_token);
                        $this->google_sync->delete_appointment($provider, $appointment['id_google_calendar']);
                    }
                } catch(Exception $exc) {
                    $exceptions[] = $exc;
                }
            }

            // :: SEND NOTIFICATION EMAILS TO CUSTOMER AND PROVIDER
            try {
                $this->load->library('Notifications');

                $send_provider = $this->providers_model
                            ->get_setting('notifications', $provider['id']);

                if ($send_provider == TRUE) {
                    $this->notifications->send_delete_appointment($appointment, $provider,
                            $service, $customer, $company_settings, $provider['email'],
                            $_POST['cancel_reason']);
                }

                $this->notifications->send_delete_appointment($appointment, $provider,
                        $service, $customer, $company_settings, $customer['email'],
                        $_POST['cancel_reason']);
            } catch(Exception $exc) {
                $exceptions[] = $exc;
            }
        } catch(Exception $exc) {
            // Display the error message to the customer.
            $exceptions[] = $exc;
        }

        $view = array(
            'message_title' => $this->lang->line('appointment_cancelled_title'),
            'message_text' => $this->lang->line('appointment_cancelled'),
            'message_icon' => $this->config->item('base_url') . '/assets/img/success.png'
        );

        if (isset($exceptions)) {
            $view['exceptions'] = $exceptions;
        }

        $this->load->view('appointments/message', $view);
    }

	/**
     * GET an specific appointment book and redirect to the success screen.
     *
     * @param int $appointment_id Contains the id of the appointment to retrieve.
     */
    public function book_success($appointment_id) {
        //if the appointment id doesn't exist or zero redirect to index
        if(!$appointment_id){
            redirect('appointments');
        }
        $this->load->model('appointments_model');
        $this->load->model('providers_model');
        $this->load->model('services_model');
        $this->load->model('settings_model');
        //retrieve the data needed in the view
        $appointment =  $this->appointments_model->get_row($appointment_id);
        $provider = $this->providers_model->get_row($appointment['id_users_provider']);
        $service = $this->services_model->get_row($appointment['id_services']);
        $company_name = $this->settings_model->get_setting('company_name');
        //get the exceptions
        $exceptions = $this->session->flashdata('book_success');
         // :: LOAD THE BOOK SUCCESS VIEW
        $view = array(
            'appointment_data'  => $appointment,
            'provider_data'     => $provider,
            'service_data'      => $service,
            'company_name'      => $company_name,
        );
        if($exceptions){
            $view['exceptions'] = $exceptions;
        }
        $this->load->view('appointments/book_success', $view);
    }

    /**
     * [AJAX] Get the available appointment hours for the given date.
     *
     * This method answers to an AJAX request. It calculates the available hours
     * for thegiven service, provider and date.
     *
     * @param numeric $_POST['service_id'] The selected service's record id.
     * @param numeric $_POST['provider_id'] The selected provider's record id.
     * @param string $_POST['selected_date'] The selected date of which the
     * available hours we want to see.
     * @param numeric $_POST['service_duration'] The selected service duration in
     * minutes.
     * @param string $$_POST['manage_mode'] Contains either 'true' or 'false' and determines
     * the if current user is managing an already booked appointment or not.
     * @return Returns a json object with the available hours.
     */
    public function ajax_get_available_hours() {
        $this->load->model('providers_model');
        $this->load->model('appointments_model');
        $this->load->model('settings_model');

        try {
            // If manage mode is TRUE then the following we should not consider the selected
            // appointment when calculating the available time periods of the provider.
            $exclude_appointments = ($_POST['manage_mode'] === 'true')
                    ? array($_POST['appointment_id'])
                    : array();

            $empty_periods = $this->get_provider_available_time_periods($_POST['provider_id'],
                    $_POST['selected_date'], $exclude_appointments);

            // Calculate the available appointment hours for the given date. The empty spaces
            // are broken down to 15 min and if the service fit in each quarter then a new
            // available hour is added to the "$available_hours" array.

            $available_hours = array();

            foreach ($empty_periods as $period) {
                $start_hour = new DateTime($_POST['selected_date'] . ' ' . $period['start']);
                $end_hour = new DateTime($_POST['selected_date'] . ' ' . $period['end']);

                $minutes = $start_hour->format('i');

                if ($minutes % 15 != 0) {
                    // Change the start hour of the current space in order to be
                    // on of the following: 00, 15, 30, 45.
                    if ($minutes < 15) {
                        $start_hour->setTime($start_hour->format('H'), 15);
                    } else if ($minutes < 30) {
                        $start_hour->setTime($start_hour->format('H'), 30);
                    } else if ($minutes < 45) {
                        $start_hour->setTime($start_hour->format('H'), 45);
                    } else {
                        $start_hour->setTime($start_hour->format('H') + 1, 00);
                    }
                }

                $current_hour = $start_hour;
                $diff = $current_hour->diff($end_hour);

                while (($diff->h * 60 + $diff->i) >= intval($_POST['service_duration'])) {
                    $available_hours[] = $current_hour->format('H:i');
                    $current_hour->add(new DateInterval("PT15M"));
                    $diff = $current_hour->diff($end_hour);
                }
            }

            // If the selected date is today, remove past hours. It is important
            // include the timeout before booking that is set in the backoffice
            // the system. Normally we might want the customer to book an appointment
            // that is at least half or one hour from now. The setting is stored in
            // minutes.
            if (date('m/d/Y', strtotime($_POST['selected_date'])) == date('m/d/Y')) {
                if ($_POST['manage_mode'] === 'true') {
                    $book_advance_timeout = 0;
                } else {
                    $book_advance_timeout = $this->settings_model->get_setting('book_advance_timeout');
                }

                foreach($available_hours as $index => $value) {
                    $available_hour = strtotime($value);
                    $current_hour = strtotime('+' . $book_advance_timeout . ' minutes', strtotime('now'));
                    if ($available_hour <= $current_hour) {
                        unset($available_hours[$index]);
                    }
                }
            }

            $available_hours = array_values($available_hours);
            sort($available_hours, SORT_STRING );
            $available_hours = array_values($available_hours);
            echo json_encode($available_hours);

        } catch(Exception $exc) {
            echo json_encode(array(
                'exceptions' => array(exceptionToJavaScript($exc))
            ));
        }
    }

    /**
     * [AJAX] Register the appointment to the database.
     */
    public function ajax_register_appointment() {
        try {

            $post_data = $_POST['post_data']; // alias

            // Validate the CAPTCHA string.
            if ($this->session->userdata('captcha_phrase') !== $_POST['captcha']) {
                throw new Exception($this->lang->line('captcha_is_wrong'));
            }

            // Check appointment availability.
            if (!$this->check_datetime_availability()) {
                throw new Exception($this->lang->line('requested_hour_is_unavailable'));
            }

            $appointment = $post_data['appointment'];
            $customer = $post_data['customer'];

            $this->load->model('appointments_model');
            $this->load->model('providers_model');
            $this->load->model('services_model');
            $this->load->model('customers_model');
            $this->load->model('settings_model');

            if ($this->customers_model->exists($customer))
                    $customer['id'] = $this->customers_model->find_record_id($customer);

            $customer_id = $this->customers_model->add($customer);
            $appointment['id_users_customer'] = $customer_id;

            $appointment['id'] = $this->appointments_model->add($appointment);
            $appointment['hash'] = $this->appointments_model->get_value('hash', $appointment['id']);

            $provider = $this->providers_model->get_row($appointment['id_users_provider']);
            $service = $this->services_model->get_row($appointment['id_services']);

            $company_settings = array(
                'company_name'  => $this->settings_model->get_setting('company_name'),
                'company_link'  => $this->settings_model->get_setting('company_link'),
                'company_email' => $this->settings_model->get_setting('company_email')
            );

            // :: SYNCHRONIZE APPOINTMENT WITH PROVIDER'S GOOGLE CALENDAR
            // The provider must have previously granted access to his google calendar account
            // in order to sync the appointment.
            try {
                $google_sync = $this->providers_model->get_setting('google_sync',
                        $appointment['id_users_provider']);

                if ($google_sync == TRUE) {
                    $google_token = json_decode($this->providers_model
                            ->get_setting('google_token', $appointment['id_users_provider']));

                    $this->load->library('google_sync');
                    $this->google_sync->refresh_token($google_token->refresh_token);

                    if ($post_data['manage_mode'] === FALSE) {
                        // Add appointment to Google Calendar.
                        $google_event = $this->google_sync->add_appointment($appointment, $provider,
                                $service, $customer, $company_settings);
                        $appointment['id_google_calendar'] = $google_event->id;
                        $this->appointments_model->add($appointment);
                    } else {
                        // Update appointment to Google Calendar.
                        $appointment['id_google_calendar'] = $this->appointments_model
                                ->get_value('id_google_calendar', $appointment['id']);

                        $this->google_sync->update_appointment($appointment, $provider,
                                $service, $customer, $company_settings);
                    }
                }
            } catch(Exception $exc) {
                log_message('error', $exc->getMessage());
                log_message('error', $exc->getTraceAsString());
            }

            // :: SEND NOTIFICATION EMAILS TO BOTH CUSTOMER AND PROVIDER
            try {
                $this->load->library('Notifications');

                $send_provider = $this->providers_model
                        ->get_setting('notifications', $provider['id']);

                if (!$post_data['manage_mode']) {
                    $customer_title = $this->lang->line('appointment_booked');
                    $customer_message = $this->lang->line('thank_you_for_appointment');
                    $customer_link = $this->config->item('base_url') . '/index.php/appointments/index/'
                            . $appointment['hash'];

                    $provider_title = $this->lang->line('appointment_added_to_your_plan');
                    $provider_message = $this->lang->line('appointment_link_description');
                    $provider_link = $this->config->item('base_url') . '/index.php/backend/index/'
                            . $appointment['hash'];
                } else {
                    $customer_title = $this->lang->line('appointment_changes_saved');
                    $customer_message = '';
                    $customer_link = $this->config->item('base_url') . '/index.php/appointments/index/'
                            . $appointment['hash'];

                    $provider_title = $this->lang->line('appointment_details_changed');
                    $provider_message = '';
                    $provider_link = $this->config->item('base_url') . '/index.php/backend/index/'
                            . $appointment['hash'];
                }

                $this->notifications->send_appointment_details($appointment, $provider,
                        $service, $customer,$company_settings, $customer_title,
                        $customer_message, $customer_link, $customer['email']);

                if ($send_provider == TRUE) {
                    $this->notifications->send_appointment_details($appointment, $provider,
                            $service, $customer, $company_settings, $provider_title,
                            $provider_message, $provider_link, $provider['email']);
                }
            } catch(Exception $exc) {
                log_message('error', $exc->getMessage());
                log_message('error', $exc->getTraceAsString());
            }

            echo json_encode(array(
                    'appointment_id' => $appointment['id']
                ));

        } catch(Exception $exc) {
            echo json_encode(array(
                'exceptions' => array(exceptionToJavaScript($exc))
            ));
        }
    }

	/**
	 * Check whether the provider is still available in the selected appointment date.
	 *
	 * It might be times where two or more customers select the same appointment date and time.
	 * This shouldn't be allowed to happen, so one of the two customers will eventually get the
	 * prefered date and the other one will have to choose for another date. Use this method
	 * just before the customer confirms the appointment details. If the selected date was taken
	 * in the mean time, the customer must be prompted to select another time for his appointment.
	 *
	 * @return bool Returns whether the selected datetime is still available.
	 */
	private function check_datetime_availability() {
		$this->load->model('services_model');

		$appointment  = $_POST['post_data']['appointment'];

		$service_duration = $this->services_model->get_value('duration', $appointment['id_services']);

		$exclude_appointments = (isset($appointment['appointment_id']))
				? array($appointment['appointment_id']) : array();

		$available_periods = $this->get_provider_available_time_periods(
				$appointment['id_users_provider'], date('Y-m-d', strtotime($appointment['start_datetime'])),
				$exclude_appointments);

		$is_still_available = FALSE;

		foreach($available_periods as $period) {
			$appt_start = new DateTime($appointment['start_datetime']);
			$appt_start = $appt_start->format('H:i');

			$appt_end = new DateTime($appointment['start_datetime']);
			$appt_end->add(new DateInterval('PT' . $service_duration . 'M'));
			$appt_end = $appt_end->format('H:i');

			$period_start = date('H:i', strtotime($period['start']));
			$period_end = date('H:i', strtotime($period['end']));

			if ($period_start <= $appt_start && $period_end >= $appt_end) {
				$is_still_available = TRUE;
				break;
			}
		}

		return $is_still_available;
	}

	/**
	 * Get an array containing the free time periods (start - end) of a selected date.
	 *
	 * This method is very important because there are many cases where the system needs to
	 * know when a provider is avaible for an appointment. This method will return an array
	 * that belongs to the selected date and contains values that have the start and the end
	 * time of an available time period.
	 *
	 * @param numeric $provider_id The provider's record id.
	 * @param string $selected_date The date to be checked (MySQL formatted string).
	 * @param array $exclude_appointments This array contains the ids of the appointments that
	 * will not be taken into consideration when the available time periods are calculated.
	 * @return array Returns an array with the available time periods of the provider.
	 */
	private function get_provider_available_time_periods($provider_id, $selected_date,
			$exclude_appointments = array()) {
		$this->load->model('appointments_model');
		$this->load->model('providers_model');

		// Get the provider's working plan and reserved appointments.
		$working_plan = json_decode($this->providers_model
										 ->get_setting('working_plan', $provider_id), true);

		$where_clause = array(
			'DATE(start_datetime)' => date('Y-m-d', strtotime($selected_date)),
			'id_users_provider' => $provider_id
		);

		$reserved_appointments = $this->appointments_model->get_batch($where_clause);

		// Sometimes it might be necessary to not take into account some appointment records
		// in order to display what the providers' available time periods would be without them.
		foreach ($exclude_appointments as $excluded_id) {
			foreach ($reserved_appointments as $index => $reserved) {
				if ($reserved['id'] == $excluded_id) {
					unset($reserved_appointments[$index]);
				}
			}
		}

		// Find the empty spaces on the plan. The first split between the plan is due to
		// a break (if exist). After that every reserved appointment is considered to be
		// a taken space in the plan.
		$selected_date_working_plan = $working_plan[strtolower(date('l', strtotime($selected_date)))];
		$available_periods_with_breaks = array();

		if (isset($selected_date_working_plan['breaks'])) {
			if (count($selected_date_working_plan['breaks'])) {
				foreach($selected_date_working_plan['breaks'] as $index=>$break) {
					// Split the working plan to available time periods that do not
					// contain the breaks in them.
					$last_break_index = $index - 1;

					if (count($available_periods_with_breaks) === 0) {
						$start_hour = $selected_date_working_plan['start'];
						$end_hour = $break['start'];
					} else {
						$start_hour = $selected_date_working_plan['breaks'][$last_break_index]['end'];
						$end_hour = $break['start'];
					}

					$available_periods_with_breaks[] = array(
						'start' => $start_hour,
						'end' => $end_hour
					);
				}

				// Add the period from the last break to the end of the day.
				$available_periods_with_breaks[] = array(
					'start' => $selected_date_working_plan['breaks'][$index]['end'],
					'end' => $selected_date_working_plan['end']
				);
			} else {
				$available_periods_with_breaks[] = array(
					'start' => $selected_date_working_plan['start'],
					'end' => $selected_date_working_plan['end']
				);
			}
		}

		// Break the empty periods with the reserved appointments.
		$available_periods_with_appointments = $available_periods_with_breaks;

		foreach($reserved_appointments as $appointment) {
			foreach($available_periods_with_appointments as $index => &$period) {

				$a_start = strtotime($appointment['start_datetime']);
				$a_end =  strtotime($appointment['end_datetime']);
				$p_start = strtotime($selected_date .  ' ' . $period['start']);
				$p_end = strtotime($selected_date .  ' ' .$period['end']);

				if ($a_start <= $p_start && $a_end <= $p_end && $a_end <= $p_start) {
					// The appointment does not belong in this time period, so we
					// will not change anything.
				} else if ($a_start <= $p_start && $a_end <= $p_end && $a_end >= $p_start) {
					// The appointment starts before the period and finishes somewhere inside.
					// We will need to break this period and leave the available part.
					$period['start'] = date('H:i', $a_end);

				} else if ($a_start >= $p_start && $a_end <= $p_end) {
					// The appointment is inside the time period, so we will split the period
					// into two new others.
					unset($available_periods_with_appointments[$index]);
					$available_periods_with_appointments[] = array(
						'start' => date('H:i', $p_start),
						'end' => date('H:i', $a_start)
					);
					$available_periods_with_appointments[] = array(
						'start' => date('H:i', $a_end),
						'end' => date('H:i', $p_end)
					);

				} else if ($a_start >= $p_start && $a_end >= $p_start && $a_start <= $p_end) {
					// The appointment starts in the period and finishes out of it. We will
					// need to remove the time that is taken from the appointment.
					$period['end'] = date('H:i', $a_start);

				} else if ($a_start >= $p_start && $a_end >= $p_end && $a_start >= $p_end) {
					// The appointment does not belong in the period so do not change anything.
				} else if ($a_start <= $p_start && $a_end >= $p_end && $a_start <= $p_end) {
					// The appointment is bigger than the period, so this period needs to be
					// removed.
					unset($available_periods_with_appointments[$index]);
				}
			}
		}

		return array_values($available_periods_with_appointments);
	}
}

/* End of file appointments.php */
/* Location: ./application/controllers/appointments.php */

<?php
/**
 * API handler.
 *
 * @package wpcable
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Handles communication with the Codeable API.
 *
 * Tip: To find available endpoints, simply examine the codeable app in your browser
 * and see, which api.codeable.com requests are made on a specific page.
 */
class wpcable_api_calls {

	/**
	 * The auth_token is used by all APIs (except the "login" endpoint) to access
	 * private data of the authenticatd user.
	 *
	 * @var string
	 */
	public $auth_token = '';

	/**
	 * Get the option to know when to stop pulling tasks
	 *
	 * @var int
	 */
	private $tasks_stop_at_page = 0;

	/**
	 * Returns the singleton instance to the API object.
	 *
	 * @return wpcable_api_calls
	 */
	public static function inst() {
		static $_inst = null;

		if ( null === $_inst ) {
			$_inst = new wpcable_api_calls();
		}

		return $_inst;
	}

	/**
	 * Initializes the API handler by loading the previous auth_token from the DB.
	 */
	private function __construct() {
		$this->get_auth_token();
		$this->tasks_stop_at_page = (int) get_option( 'wpcable_tasks_stop_at_page', 0 );
	}

	/**
	 * Generates an auth_token from the given email/password pair.
	 *
	 * @param  string $email    E-Mail address to use for login.
	 * @param  string $password The users password, as entered on app.codeable.com.
	 * @return void
	 */
	public function login( $email, $password ) {
		$args = [
			'email'    => $email,
			'password' => $password,
		];

		$this->auth_token = '';
		$url              = 'users/login';
		$login_call       = $this->request( $url, $args );

		// Credential error checking.
		if (
			isset( $login_call['errors'] ) &&
			! empty( $login_call['errors'][0]['message'] ) &&
			'Invalid credentials' === $login_call['errors'][0]['message']
		) {
			$redirect_to = codeable_add_message_param( 'error', 'credentials' );

			wp_safe_redirect( $redirect_to );
			exit;
		}

		$this->set_auth_token( $login_call['auth_token'] );

		$account_details = $login_call;
		unset( $account_details['auth_token']);
		update_option( 'wpcable_account_details', $account_details );

		update_option( 'wpcable_average', $account_details['stats']['avg_task_size'] );
		update_option( 'wpcable_balance', $account_details['balance'] );
	}

	/**
	 * Checks, whether we know the auth-token from a previous API call.
	 *
	 * @return bool
	 */
	public function auth_token_known() {
		return !! $this->auth_token;
	}

	/**
	 * Encrypt the auth_token and store it in the options table for future usage.
	 *
	 * @param  string $token The auth_token.
	 * @return void
	 */
	private function set_auth_token( $token ) {
		$iv  = openssl_random_pseudo_bytes( 16 );
		$enc = openssl_encrypt( $token, 'AES-256-CBC', AUTH_SALT, 0, $iv );

		$full_enc = base64_encode( $iv ) . ':' . base64_encode( $enc );

		update_option( 'wpcable_auth_token', $full_enc );

		$this->auth_token = $token;
	}

	/**
	 * Read the encrypted auth_token from the options table and decrypt it.
	 *
	 * @return void
	 */
	private function get_auth_token() {
		$this->auth_token = '';

		$value = get_option( 'wpcable_auth_token' );

		if ( $value ) {
			list ( $iv, $enc ) = explode( ':', $value );

			$iv    = base64_decode( $iv );
			$enc   = base64_decode( $enc );
			$token = openssl_decrypt( $enc, 'AES-256-CBC', AUTH_SALT, 0, $iv );

			$this->auth_token = $token;
		}
	}

	/**
	 * Returns the users profile details.
	 *
	 * @return array
	 */
	public function self() {
		$url        = 'users/me';
		$login_call = $this->request( $url, [], 'get' );

		unset( $login_call['auth_token'] );

		return $login_call;
	}

	/**
	 * Get a batch of transactions of the current user.
	 *
	 * @param  int $page Pagination offset. First $page = 1.
	 * @return array
	 */
	public function transactions_page( $page = 1 ) {
		$url  = 'experts/transactions/';
		$args = [ 'page' => $page ];

		$transactions = $this->request( $url, $args, 'get' );

		return $transactions;
	}

	/**
	 * Get a batch of up to 20 tasks of the current user.
	 *
	 * @param  string $filter Task-Filter, [pending|active|archived|preferred].
	 * @param  int    $page   Pagination offset. First page is $page = 1.
	 * @return array
	 */
	public function tasks_page( $filter = 'preferred', $page = 1 ) {
		if ( 'hidden_tasks' === $filter ) {
			$url = 'users/me/hidden_tasks/';
			$num = 50;
		} else {
			$url = 'users/me/tasks/' . $filter;
			$num = 20;
		}

		$args = [
			'page'     => $page,
			'per_page' => $num,
		];

		// Stop at the next page before it does the call
		if ( $this->tasks_stop_at_page
		     && ( $this->tasks_stop_at_page + 1 ) === $page ) {
			return [];
		}

		$tasks = $this->request( $url, $args, 'get' );

		return $tasks;
	}

	/**
	 * Set off an API call too api.codeable.com and return the result as array.
	 *
	 * @param  string $url     API endpoint.
	 * @param  array  $args    Additional URL params or post data.
	 * @param  string $method  Request method [GET|POST].
	 * @param  array  $headers Optional HTTP headers.
	 * @return array
	 */
	private function request( $url, $args = [], $method = 'POST', $headers = [] ) {
		$response_body = false;

		set_time_limit( 300 );

		$method       = strtoupper( $method );
		$request_args = [ 'method' => $method ];
		$url          = 'https://api.codeable.io/' . ltrim( $url, '/' );

		if ( ! empty( $args ) ) {
			if ( 'GET' === $method ) {
				$url = add_query_arg( $args, $url );
			} else {
				$request_args['body'] = $args;
			}
		}

		$request_args['headers'] = $headers;

		if ( $this->auth_token_known() ) {
			$request_args['headers']['Authorization'] = 'Bearer ' . $this->auth_token;
		}

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			trigger_error(
				sprintf(
					'Request failed with error %1$s: %2$s',
					$response->get_error_code(), $response->get_error_message()
				),
				E_USER_ERROR
			);
			return false;
		}

		$response_body = json_decode( $response['body'], true );

		if ( is_array( $response_body ) && ! empty( $response_body['errors'] ) ) {
			if ( false !== array_search( 'Invalid login credentials', $response_body['errors'], true ) ) {
				// The auth_token expired or login failed: Clear the token!
				// Next time the user visits the settings page, they need to login again.
				codeable_api_logout();
				return false;
			}
		}

		$data = $response['headers']->getAll();

		if ( isset( $data['auth-token'] ) ) {
			$response_body['auth_token'] = $data['auth-token'];
		}

		return $response_body;
	}
}

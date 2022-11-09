<?php

defined('MOODLE_INTERNAL') || die();

require_once $CFG->dirroot . '/auth/mykooboauth2/lib/MykoobOAuth.php';
require_once $CFG->dirroot . '/auth/mykooboauth2/locallib.php';
require_once $CFG->dirroot . '/cohort/lib.php';
require_once $CFG->libdir . '/authlib.php';

class auth_plugin_mykooboauth2 extends auth_plugin_base
{
    /** @var MykoobOAuth */
    private $mykooboauth;

    public function __construct()
    {
        $this->authtype = 'mykooboauth2';
        $this->config = get_config('auth/' . $this->authtype);
    }

    public function loginpage_hook()
    {
        global $CFG, $DB, $SESSION;

        $provider = optional_param('provider', '', PARAM_TEXT);
        if (empty($provider) || $provider !== $this->authtype) {
            return;
        }

        $code = optional_param('code', '', PARAM_TEXT);
        if (empty($code)) {
            if ($this->config->debug) {
                error_log('[Mykoob OAuth2] Missing authorization code.');
            }
            print_error('error_missingcode', 'auth_' . $this->authtype, null);
        }

        $this->mykooboauth = new MykoobOAuth($this->config->client_id, $this->config->client_secret);
        $token = $this->get_access_token($code);
        $userinfo = $this->get_user_info($token);
        $username = $this->get_username($userinfo);
        $userschools = $this->get_user_schools($token);
        $pers_code = $this->get_user_pers_code($token);
        $user_basic = $this->get_user_basic($token);

        $user = $DB->get_record('user', array('username' => $username, 'mnethostid' => $CFG->mnet_localhost_id));
        if (!$user) {
            $user = create_user_record($username, null, $this->authtype);
        }

        $SESSION->username = $username;
        if (!authenticate_user_login($username, null)) {
            print_error('error_failedauth', 'auth_' . $this->authtype);
        }

        $user->firstname = $userinfo['name'];
        $user->lastname = $userinfo['surname'];
        $user->email = !empty($userinfo['email']) ? $userinfo['email'] : $user->email;
        $user->idnumber = $pers_code['personal_id'];
        $user->school = $userschools['schools']['0']['name'];
        $user->school_code = $userschools['schools']['0']['school_reg_no'];
        $user->role = $userschools['schools']['0']['roles']['0'];

        if (!empty($user_basic['Student']['UserSchools'][$user->school]['ClassName'])) {
            $class = $user_basic['Student']['UserSchools'][$user->school]['ClassName'];
            $user->classlevel = strtok($class, ".");
            $user->classname = str_replace($user->classlevel, "", $class);
        }

        try {
            $DB->update_record('user', $user);
        } catch (moodle_exception $e) {
            if ($this->config->debug) {
                error_log('[Mykoob OAuth2] Failed to update user.' . $e->getMessage() . '\n Serialized user data:  (' . serialize($user) . ')');
            }
            print_error('error_failedupdateuser', 'auth_' . $this->authtype);
        }

        complete_user_login($user);

        $goto = $CFG->wwwroot . '/';
        // arī pārbauda vai ieslēgta pāradresācija uz formu saņemto datu pabeigšanai
        if ((isset($this->config->forcecomplete) && $this->config->forcecomplete) && !$completed) {
            $goto = $CFG->wwwroot . '/local/eu/complete.php';
        } else {
            if (user_not_fully_set_up($user)) {
                $goto = $CFG->wwwroot . '/user/edit.php';
            } else if (isset($SESSION->wantsurl) && (strpos($SESSION->wantsurl, $CFG->wwwroot) === 0)) {
                $goto = $SESSION->wantsurl;
                unset($SESSION->wantsurl);
            }
        }
        redirect($goto);
    }

    /**
     * @param string $username
     * @param null $password
     * @return bool
     */
    public function user_login($username, $password)
    {
        global $CFG, $DB, $SESSION;

        if (isset($SESSION->username) && $SESSION->username == $username) {
            if ($user = $DB->get_record("user", array('username' => $username, 'auth' => $this->authtype, 'mnethostid' => $CFG->mnet_localhost_id))) {
                unset($SESSION->username);
                return true;
            }
        }
        return false;
    }

    /**
     * @param object $config
     * @param object $err
     * @param array $user_fields
     */
    public function config_form($config, $err, $user_fields)
    {
        $config->client_id = (isset($config->client_id) ? $config->client_id : '');
        $config->client_secret = (isset($config->client_secret) ? $config->client_secret : '');
        $config->redirect_url = (isset($config->redirect_url) ? $config->redirect_url : '');
        $config->grant_type = (isset($config->grant_type) ? $config->grant_type : 'authorization_code');
        $config->response_type = (isset($config->response_type) ? $config->response_type : 'code');
        $config->scope = (isset($config->scope) ? $config->scope : 'user_info');
        $config->usernameprefix = (isset($config->usernameprefix) ? $config->usernameprefix : 'mk_');
        $config->debug = (isset($config->debug) ? $config->debug : '0');

        include 'config.html';
    }

    /**
     * Saglabā autentifikācijas moduļa konfigurāciju. Ja konfigurācijas atribūta vērtība netika norādīta,
     * tad šim atribūtam saglabā sākumu vērtību.
     *
     * @param $config
     * @return bool
     */
    public function process_config($config)
    {
        set_config('client_id', $config->client_id, "auth/{$this->authtype}");
        if ($config->client_secret !== 'password') {
            set_config('client_secret', $config->client_secret, "auth/{$this->authtype}");
        }
        set_config('redirect_url', $config->redirect_url, "auth/{$this->authtype}");
        set_config('grant_type', (!empty($config->grant_type) ? $config->grant_type : 'authorization_code'), "auth/{$this->authtype}");
        set_config('response_type', (!empty($config->response_type) ? $config->response_type : 'code'), "auth/{$this->authtype}");
        set_config('scope', (!empty($config->scope) ? $config->scope : 'user_info'), "auth/{$this->authtype}");
        set_config('usernameprefix', (!empty($config->usernameprefix) ? $config->usernameprefix : 'mk_'), "auth/{$this->authtype}");
        set_config('debug', (isset($config->debug) ? 1 : 0), "auth/{$this->authtype}");

        return true;
    }

    /**
     * Ja autentifikācijas modulis aktivēts, tad šis provaiders pieejams uz lappuses ar login formu.
     *
     * @param string $wantsurl
     * @return array
     */
    public function loginpage_idp_list($wantsurl)
    {
        $config = $this->config;
        if (empty($config->client_id) ||
            empty($config->redirect_url) ||
            empty($config->scope)
        ) {
            if ($config->debug) {
                error_log('[Mykoob OAuth2] Auth plugin not configured.');
            }
            return parent::loginpage_idp_list($wantsurl);
        }

        $idpurl = MykoobOAuth::getAuthorizeUrl($config->client_id, $config->redirect_url, $config->scope);
        return array(
            array(
                'url' => new mykoob_moodle_url($idpurl),
                'icon' => new pix_icon('mykoob', 'Mykoob', 'auth_' . $this->authtype)
            )
        );
    }

    public function can_signup()
    {
        return false;
    }

    public function can_confirm()
    {
        return false;
    }

    public function can_edit_profile()
    {
        return true;
    }

    public function can_change_password()
    {
        return false;
    }

    public function can_reset_password()
    {
        return false;
    }

    public function is_internal()
    {
        return false;
    }

    public function prevent_local_passwords()
    {
        return true;
    }

    public function is_synchronised_with_external()
    {
        return false;
    }

    /**
     * Pieliek prefiksu pie lietotāja vārda.
     *
     * @param object $user_info paņemti dati caur api
     * @return string
     */
    protected function get_username($user_info)
    {
        if (!isset($user_info['user_id']) || empty($user_info['user_id'])) {
            if ($this->config->debug) {
                error_log('[Mykoob OAuth2] User info not contain user id.');
            }
            print_error('error_missinguserid', 'auth_' . $this->authtype);
        }
        return $this->config->usernameprefix . $user_info['user_id'];
    }

    /**
     * Datu saņemšana notiek tikai uz autetifikācijas etapa, tāpēc access token nekur nesaglabājas. Ja nepieciešams
     * saņemt dati arī citā vietā, tad saglabājiet atslēgu sesijā vai datubāzē.
     *
     * @param string $code
     * @return string
     */
    final protected function get_access_token($code)
    {
        $result = $this->mykooboauth->getAccessToken($code);
        if ($this->mykooboauth->getHttpCode() !== 200) {
            if ($this->config->debug) {
                error_log('[Mykoob OAuth2] Get access token request failed.' .
                    ' Error: "' . $result->error . '"/"' . $result->error_description . '"');
            }
            print_error('error_failedgettoken', 'auth_' . $this->authtype);
        }
        return $result['access_token'];

    }

    /**
     * Dati no api "info-old".
     * <pre>
     * stdClass
     *  [name] = "vārds"
     *  [surname] = "uzvārds"
     *  [email] = "elektroniskais pasts"
     *  [country_code] = "valsts kods vai ---, ja valsts netika izvēlēts"
     *  [city] = "pilsēta"
     *  [user_id] = "lietotāja identifikators"
     * </pre>
     *
     * @param string $access_token
     * @return object $userinfo
     */
    final protected function get_user_info($access_token)
    {
        $result = $this->mykooboauth->getResource('user/info-old', $access_token);
        if ($this->mykooboauth->getHttpCode() !== 200) {
            if ($this->config->debug) {
                error_log('[Mykoob OAuth2] Get resource "view-user" request failed.' .
                    ' Error: "' . $result->error . '"/"' . $result->error_description . '"');
            }
            print_error('error_failedgetuserinfo', 'auth_' . $this->authtype);
        }

        return $result['user_info'];
    }

    /**
     * Dati no "school_info-old" api.
     * <pre>
     * stdClass
     *  [name] = "vārds",
     *  [surname] = "uzvārds",
     *  [email] = "elektroniskais pasts",
     *  [user_id] = "lietotāja identifikators",
     *  ["Student/Teacher/Parent"] => array
     *      [UserSchools] => array
     *          [skolas nosaukums] = stdClass
     *              [classname] = "klases nosaukums",
     *              [subjects] => array
     *                  [priekšmeta nosaukums],
     *                  [...]
     *          }
     *      }
     *  }
     * </pre>
     *
     * @param string $acccess_token
     * @return array
     */
    final protected function get_user_schools($acccess_token)
    {
        $result = $this->mykooboauth->getResource('user/school_info-old', $acccess_token);
        if ($this->mykooboauth->getHttpCode() !== 200) {
            if ($this->config->debug) {
                error_log('[Mykoob OAuth2] Get resource "user/school_info-old" request failed.' .
                    ' Error: "' . $result->error . '"/"' . $result->error_description . '"');
            }
            print_error('error_failedgetuserinfo', 'auth_' . $this->authtype);
        }
        return $result['user_school_info'];
    }

    /**
     * Dati no api "personal_id-old".
     * <pre>
     *  [personal_id] => array
     *      [personal_id] = "Personas kods"
     * @param string $access_token
     * @return object $personal_id
     */


    final protected function get_user_pers_code($acccess_token)
    {
        $result = $this->mykooboauth->getResource('user/personal_id-old', $acccess_token);
        if ($this->mykooboauth->getHttpCode() !== 200) {
            if ($this->config->debug) {
                error_log('[Mykoob OAuth2] Get resource "user/personal_id-old" request failed.' .
                    ' Error: "' . $result->error . '"/"' . $result->error_description . '"');
            }
            print_error('error_failedgetuserinfo', 'auth_' . $this->authtype);
        }
        return $result['personal_id'];
    }


    final protected function get_user_basic($acccess_token)
    {
        $result = $this->mykooboauth->getResource('user/basic-old', $acccess_token);

        if ($this->mykooboauth->getHttpCode() !== 200) {
            if ($this->config->debug) {
                error_log('[Mykoob OAuth2] Get resource "user/personal_id-old" request failed.' .
                    ' Error: "' . $result->error . '"/"' . $result->error_description . '"');
            }
            print_error('error_failedgetuserinfo', 'auth_' . $this->authtype);
        }
        return $result['UserGroups'];
    }

    /**
     * Saglabā vai atjaunina datus par skolnieka/skolotāja skolu.
     *
     * @param array $userschools dati, kas tika saņemti no user_eduspace API
     * @param int $userid
     * @return array
     */
    protected function save_userschools($userschools, $userid)
    {
        $eudata = mykoob_convert_to_eu_data($userschools);
        if (eu_has_update($eudata, $userid)) {
            error_log('eu_has_update ');
            eu_save_data($eudata, $userid);
        }
        return $eudata;
    }
}

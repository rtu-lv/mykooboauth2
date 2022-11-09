<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Pārveido saņemti dati no Mykoob 'user_eduspace' API vienotā eduspace formātā.
 *
 * @param array $data
 * @return array
 */
function mykoob_convert_to_eu_data($data)
{
    $eudata = array();

    $roles = array_keys($data);
    foreach ($roles as $rolename) {
        $convertedschools = array();
        switch ($rolename) {
            case ROLE_STUDENT:
            case ROLE_TEACHER:
                $roledata = $data[$rolename];
                $userschools = (isset($roledata['UserSchools']) ? $roledata['UserSchools'] : '');
                $convertedschools = eu_convert_to_schools($userschools);

                foreach ($convertedschools['schools'] as $school) {
                    $schoolname = $school->data;
                    $entities = array();
                    if (is_array($userschools[$schoolname])) {
                        foreach ($userschools[$schoolname] as $entityname => $entityvalue) {
                            if ($entityname === 'ClassName') {
                                if (!$classname = eu_convert_to_classname($entityvalue)) {
                                    error_log('[Mykoob OAuth2] Failed to get class name. User data: ' . serialize($userschools[$schoolname]));
                                }
                                $entities = array($classname);
                            } else if ($entityname === 'Subjects') {
                                $subjects = eu_convert_to_subjects($entityvalue);
                                if (!empty($entities)) {
                                    $subjects = array_merge($entities, $subjects);
                                }
                                $entities = $subjects;
                            } else {
                                error_log('[Mykoob OAuth2] Undefined school entity. User data: ' . serialize($userschools[$schoolname]));
                            }
                        }
                    }
                    $school->entities = $entities;
                }
                break;
            case ROLE_PARENT:
                break;
            default:
        }
        $eudata[$rolename] = $convertedschools;
    }
    return $eudata;
}

class mykoob_moodle_url extends moodle_url
{
    private $mykooburl;

    public function __construct($url, array $params = null)
    {
        parent::__construct($url);
        $this->mykooburl = $url;
    }

    /**
     * @param bool $escaped
     * @param array $overrideparams
     * @return string
     */
    public function get_query_string($escaped = true, array $overrideparams = null)
    {
        $arr = array();
        if ($overrideparams !== null) {
            $params = $this->merge_overrideparams($overrideparams);
        } else {
            $params = $this->params;
        }

        foreach ($params as $key => $val) {
            if (!empty($val)) {
                if (is_array($val)) {
                    foreach ($val as $index => $value) {
                        $arr[] = rawurlencode($key . '[' . $index . ']') . "=" . rawurlencode($value);
                    }
                } else {
                    $arr[] = rawurlencode($key) . "=" . rawurlencode($val);
                }
                // ja ir atribūta nosaukums, bet nav vērtības
            } else {
                // pārbauda vai atribūta nosaukums beidzas uz '/'
                if (strpos(substr($key, 0, -1), '/') !== 0) {
                    $arr[] = $key;
                }
            }
        }

        if ($escaped) {
            return implode('&amp;', $arr);
        } else {
            return implode('&', $arr);
        }
    }
}

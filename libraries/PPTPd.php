<?php

/**
 * PPTP VPN server class.
 *
 * @category   apps
 * @package    pptpd
 * @subpackage libraries
 * @author     ClearFoundation <developer@clearfoundation.com>
 * @copyright  2003-2013 ClearFoundation
 * @license    http://www.gnu.org/copyleft/lgpl.html GNU Lesser General Public License version 3 or later
 * @link       http://www.clearfoundation.com/docs/developer/apps/pptpd/
 */

///////////////////////////////////////////////////////////////////////////////
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU Lesser General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Lesser General Public License for more details.
//
// You should have received a copy of the GNU Lesser General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.
//
///////////////////////////////////////////////////////////////////////////////

///////////////////////////////////////////////////////////////////////////////
// N A M E S P A C E
///////////////////////////////////////////////////////////////////////////////

namespace clearos\apps\pptpd;

///////////////////////////////////////////////////////////////////////////////
// B O O T S T R A P
///////////////////////////////////////////////////////////////////////////////

$bootstrap = getenv('CLEAROS_BOOTSTRAP') ? getenv('CLEAROS_BOOTSTRAP') : '/usr/clearos/framework/shared';
require_once $bootstrap . '/bootstrap.php';

///////////////////////////////////////////////////////////////////////////////
// T R A N S L A T I O N S
///////////////////////////////////////////////////////////////////////////////

clearos_load_language('pptpd');

///////////////////////////////////////////////////////////////////////////////
// D E P E N D E N C I E S
///////////////////////////////////////////////////////////////////////////////

// Classes
//--------

use \clearos\apps\base\Daemon as Daemon;
use \clearos\apps\base\File as File;
use \clearos\apps\network\Iface as Iface;
use \clearos\apps\network\Iface_Manager as Iface_Manager;
use \clearos\apps\network\Network_Utils as Network_Utils;
use \clearos\apps\samba_common\Samba as Samba;

clearos_load_library('base/Daemon');
clearos_load_library('base/File');
clearos_load_library('network/Iface');
clearos_load_library('network/Iface_Manager');
clearos_load_library('network/Network_Utils');
clearos_load_library('samba_common/Samba');

// Exceptions
//-----------

use \Exception as Exception;
use \clearos\apps\base\Engine_Exception as Engine_Exception;
use \clearos\apps\base\File_No_Match_Exception as File_No_Match_Exception;
use \clearos\apps\base\File_Not_Found_Exception as File_Not_Found_Exception;
use \clearos\apps\base\Validation_Exception as Validation_Exception;

clearos_load_library('base/Engine_Exception');
clearos_load_library('base/File_No_Match_Exception');
clearos_load_library('base/File_Not_Found_Exception');
clearos_load_library('base/Validation_Exception');

///////////////////////////////////////////////////////////////////////////////
// C L A S S
///////////////////////////////////////////////////////////////////////////////

/**
 * PPTP VPN server class.
 *
 * @category   apps
 * @package    pptpd
 * @subpackage libraries
 * @author     ClearFoundation <developer@clearfoundation.com>
 * @copyright  2003-2013 ClearFoundation
 * @license    http://www.gnu.org/copyleft/lgpl.html GNU Lesser General Public License version 3 or later
 * @link       http://www.clearfoundation.com/docs/developer/apps/pptpd/
 */

class PPTPd extends Daemon
{
    ///////////////////////////////////////////////////////////////////////////////
    // C O N S T A N T S
    ///////////////////////////////////////////////////////////////////////////////

    const FILE_APP_CONFIG = '/etc/clearos/pptpd.conf';
    const FILE_CONFIG = '/etc/pptpd.conf';
    const FILE_OPTIONS = '/etc/ppp/options.pptpd';
    const FILE_STATS = '/proc/net/dev';
    const CONSTANT_PPPNAME = 'pptp-vpn';
    const DEFAULT_KEY_SIZE = 128;

    ///////////////////////////////////////////////////////////////////////////////
    // M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Pptp constructor.
     */

    public function __construct()
    {
        clearos_profile(__METHOD__, __LINE__);

        parent::__construct('pptpd');
    }

    /**
     * Auto configures PPTP.
     *
     * @return void
     * @throws Engine_Exception
     */

    public function auto_configure()
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! $this->get_auto_configure_state())
            return;
            
        $ifaces = new Iface_Manager();

        // Local / Remote IP configuration
        //--------------------------------

        $lans = $ifaces->get_most_trusted_networks();

        if (! empty($lans[0])) {
            list($ip, $netmask) = preg_split('/\//', $lans[0]);
            $base_ip = preg_replace('/\.[0-9]+$/', '', $ip);

            if (!Network_Utils::is_private_ip($base_ip . '.1')) {
                 $base_ip = '192.168.222';
                 $local_range = '1-99';
                 $remote_range = '100-199';
            } else {
                 $local_range = '80-89';
                 $remote_range = '90-99';
            }

            $this->set_local_ip($base_ip . '.' . $local_range);
            $this->set_remote_ip($base_ip . '.' . $remote_range);
        }

        // DNS server configuration
        //-------------------------

        $ips = $ifaces->get_most_trusted_ips();

        if ((!empty($ips[0])) && clearos_app_installed('dns'))
            $this->set_dns_server($ips[0]);
        else
            $this->set_dns_server('');

        // WINS server configuration
        //--------------------------

        $samba = new Samba();

        $is_wins = $samba->get_wins_support();
        $wins_server = $samba->get_wins_server();

        if ($is_wins && (!empty($ips[0]))) {
            $this->set_wins_server($ips[0]);
        } else if (!empty($wins_server)) {
            $this->set_wins_server($wins_server);
        } else {
            $this->set_wins_server('');
        }

        // Restart
        //--------

        $this->reset();
    }

    /**
     * Returns list of active interfaces.
     *
     * @return array list of active PPTP connections
     * @throws Engine_Exception
     */

    public function get_active_list()
    {
        clearos_profile(__METHOD__, __LINE__);

        $ethlist = array();
        $ethinfolist = array();

        $ifs = new Iface_Manager();
        $ethlist = $ifs->get_interfaces();

        foreach ($ethlist as $eth) {
            if (! preg_match('/^pptp[0-9]/', $eth))
                continue;

            $ifdetails = array();

            $if = new Iface($eth);

            // TODO: YAPH - yet another PPPoE hack
            if ($if->is_configured())
                continue;

            $address = $if->get_live_ip();
            $remote = $if->get_live_ip();

            $ifinfo = array();
            $ifinfo['name'] = $eth;
            $ifinfo['address'] = $address;

            $ethinfolist[] = $ifinfo;
        }

        return $ethinfolist;
    }

    /**
     * Returns auto-configure state.
     *
     * @return boolean state of auto-configure mode
     */

    public function get_auto_configure_state()
    {
        clearos_profile(__METHOD__, __LINE__);

        try {
            $file = new File(self::FILE_APP_CONFIG);
            $value = $file->lookup_value("/^auto_configure\s*=\s*/i");
        } catch (File_Not_Found_Exception $e) {
            return FALSE;
        } catch (File_No_Match_Exception $e) {
            return FALSE;
        } catch (Exception $e) {
            throw new Engine_Exception($e->get_message());
        }

        if (preg_match('/yes/i', $value))
            return TRUE;
        else
            return FALSE;
    }

    /**
     * Returns the DNS server.
     *
     * @return string DNS server
     * @throws Engine_Exception
     */

    public function get_dns_server()
    {
        clearos_profile(__METHOD__, __LINE__);

        return $this->_get_options_parameter('ms-dns');
    }

    /**
     * Returns interface statistics.
     *
     * @return array interface statistics
     * @throws Engine_Exception
     */

    public function get_interface_statistics()
    {
        clearos_profile(__METHOD__, __LINE__);

        // TODO: move this to the Iface class
        $stats = array();

        $file = new File(self::FILE_STATS);
        $lines = $file->get_contents_as_array();

        $matches = array();

        foreach ($lines as $line) {
            if (preg_match('/^\s*([^:]*):(.*)/', $line, $matches)) {
                $items = preg_split('/\s+/', $matches[2]);
                $stats[$matches[1]]['received'] = $items[1];
                $stats[$matches[1]]['sent'] = $items[9];
            }
        }

        return $stats;
    }

    /**
     * Returns the local IP settings.
     *
     * @return string local IP
     * @throws Engine_Exception
     */

    public function get_local_ip()
    {
        clearos_profile(__METHOD__, __LINE__);

        return $this->_get_config_parameter('localip');
    }

    /**
     * Returns remote IP settings.
     *
     * @return string remote IP
     * @throws Engine_Exception
     */

    public function get_remote_ip()
    {
        clearos_profile(__METHOD__, __LINE__);

        return $this->_get_config_parameter('remoteip');
    }

    /**
     * Returns the  WINS server.
     *
     * @return string WINS server
     * @throws Engine_Exception
     */

    public function get_wins_server()
    {
        clearos_profile(__METHOD__, __LINE__);

        return $this->_get_options_parameter('ms-wins');
    }

    /**
     * Sets auto-configure state.
     *
     * @param boolean $state state
     *
     * @return void
     * @throws Engine_Exception, Validation_Exception
     */

    public function set_auto_configure_state($state)
    {
        clearos_profile(__METHOD__, __LINE__);

        $config_value = ($state) ? 'yes' : 'no';

        $file = new File(self::FILE_APP_CONFIG);

        if ($file->exists())
            $file->delete();

        $file->create('root', 'root', '0644');

        $file->add_lines("auto_configure = $config_value\n");
    }

    /**
     * Sets the DNS server.
     *
     * @param string $server DNS server
     *
     * @return void
     * @throws Engine_Exception, Validation_Exception
     */

    public function set_dns_server($server)
    {
        clearos_profile(__METHOD__, __LINE__);

        Validation_Exception::is_valid($this->validate_dns_server($server));

        $this->_set_options_parameter('ms-dns', $server);
    }

    /**
     * Sets local IP.
     *
     * @param string $ip local IP
     *
     * @return void
     * @throws Engine_Exception, Validation_Exception
     */

    public function set_local_ip($ip)
    {
        clearos_profile(__METHOD__, __LINE__);

        Validation_Exception::is_valid($this->validate_ip_range($ip));

        $this->_set_config_parameter('localip', $ip);
    }

    /**
     * Sets remote IP.
     *
     * @param string $ip remote IP
     *
     * @return void
     * @throws Engine_Exception, Validation_Exception
     */

    public function set_remote_ip($ip)
    {
        clearos_profile(__METHOD__, __LINE__);

        Validation_Exception::is_valid($this->validate_ip_range($ip));

        $this->_set_config_parameter('remoteip', $ip);
    }


    /**
     * Sets the WINS server.
     *
     * @param string $server WINS server
     *
     * @return void
     * @throws Engine_Exception, Validation_Exception
     */

    public function set_wins_server($server)
    {
        clearos_profile(__METHOD__, __LINE__);

        Validation_Exception::is_valid($this->validate_wins_server($server));

        $this->_set_options_parameter('ms-wins', $server);
    }

    ///////////////////////////////////////////////////////////////////////////////
    // V A L I D A T I O N   R O U T I N E S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Validation routine for localip/remoteip.
     *
     * @param string $ip PPTP IP address format
     *
     * @return string error message if IP format is invalid
     */

    public function validate_ip_range($ip)
    {
        clearos_profile(__METHOD__, __LINE__);

        // TODO: improve interface
        //    return lang('pptpd_ip_range_invalid');
    }

    /**
     * Validation routine for WINS server.
     *
     * @param string $server WINS server
     *
     * @return string error message if WINS server is invalid
     */

    public function validate_wins_server($server)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (($server !== '') && (! Network_Utils::is_valid_ip($server)))
            return lang('pptpd_wins_server_invalid');
    }

    /**
     * Validation routine for DNS server.
     *
     * @param string $server DNS server
     *
     * @return string error message if DNS server is invalid
     */

    public function validate_dns_server($server)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (empty($server))
            return;

        if (! Network_Utils::is_valid_ip($server))
            return lang('pptpd_dns_server_invalid');
    }

    ///////////////////////////////////////////////////////////////////////////////
    // P R I V A T E  M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Returns parameter from ppp options file.
     *
     * @param string $parameter parameter in options file
     *
     * @access private
     * @return void
     */

    protected function _get_options_parameter($parameter)
    {
        clearos_profile(__METHOD__, __LINE__);

        $value = '';

        try {
            $file = new File(self::FILE_OPTIONS);
            $value = $file->lookup_value("/^$parameter\s+/i");
        } catch (File_No_Match_Exception $e) {
            return;
        } catch (Exception $e) {
            throw new Engine_Exception($e->get_message());
        }

        return $value;
    }

    /**
     * Returns parameter from PPTP configuration file.
     *
     * @param string $parameter parameter in options file
     *
     * @access private
     * @return void
     */

    protected function _get_config_parameter($parameter)
    {
        clearos_profile(__METHOD__, __LINE__);

        $value = '';

        try {
            $file = new File(self::FILE_CONFIG);
            $value = $file->lookup_value("/^$parameter\s+/i");
        } catch (File_No_Match_Exception $e) {
            return;
        } catch (Exception $e) {
            throw new Engine_Exception($e->get_message());
        }

        return $value;
    }

    /**
     * Sets parameter in ppp options file.
     *
     * @param string $parameter parameter in options file
     * @param string $value     value for given parameter
     *
     * @access private
     * @return void
     */

    protected function _set_options_parameter($parameter, $value)
    {
        clearos_profile(__METHOD__, __LINE__);

        $file = new File(self::FILE_OPTIONS);

        if (empty($value)) {
            $file->delete_lines("/^$parameter\s*/i");
        } else {
            $match = $file->replace_lines("/^$parameter\s*/i", "$parameter $value\n");

            if (!$match)
                $file->add_lines_after("$parameter $value\n", "/^[^#]/");
        }
    }

    /**
     * Sets parameter in PPTP configuration file.
     *
     * @param string $parameter parameter in options file
     * @param string $value     value for given parameter
     *
     * @access private
     * @return void
     */

    protected function _set_config_parameter($parameter, $value)
    {
        clearos_profile(__METHOD__, __LINE__);

        $file = new File(self::FILE_CONFIG);

        $match = $file->replace_lines("/^$parameter\s*/i", "$parameter $value\n");

        if (!$match)
            $file->add_lines_after("$parameter $value\n", "/^[^#]/");
    }
}

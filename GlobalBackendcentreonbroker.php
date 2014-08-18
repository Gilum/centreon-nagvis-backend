<?php
/*****************************************************************************
 *
 * GlobalBackendcentreonbroker.php - backend class for handling object and state
 *                           information stored in the Centreon Broker database.
 *
 * Copyright (c) 2004-2011 NagVis Project (Contact: info@nagvis.org)
 *
 * License:
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2 as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 *
 *****************************************************************************/

/**
 * @author  Maximilien Bersoult <mbersoult@merethis.com>
 */
class GlobalBackendcentreonbroker implements GlobalBackendInterface {
    private $_CORE = null;
    private $_backendId = 0;
    private $_dbname;
    private $_dbuser;
    private $_dbpass;
    private $_dbhost;
    private $_dbport;
    private $_dbinstancename;

    private $_dbh;

    private $_instanceId = 0;
    private $_cacheHostId = array();
    private $_cacheServiceId = array();
    private $_cacheHostAck = array();

    private static $_validConfig = array(
        'dbhost' => array(
            'must' => 1,
            'editable' => 1,
            'default' => 'localhost',
            'match' => MATCH_STRING_NO_SPACE
        ),
        'dbport' => array(
            'must' => 0,
            'editable' => 1,
            'default' => '3306',
            'match' => MATCH_INTEGER
        ),
        'dbname' => array(
            'must' => 1,
            'editable' => 1,
            'default' => 'centreon_storage',
            'match' => MATCH_STRING_NO_SPACE
        ),
        'dbuser' => array(
            'must' => 1,
            'editable' => 1,
            'default' => 'centreon',
            'match' => MATCH_STRING_NO_SPACE
        ),
        'dbpass' => array(
            'must' => 0,
            'editable' => 1,
            'default' => '',
            'match' => MATCH_STRING_NO_SPACE
        ),
        'dbinstancename' => array(
            'must' => 0,
            'editable' => 1,
            'default' => 'default',
            'match' => MATCH_STRING_NO_SPACE
        )
    );

    /**
     * Constructor
     *
     * @author Maximilien Bersoult <mbersoult@merethis.com>
     * @param GlobalCore $CORE The instance of NagVis
     * @param int $backendId The backend ID
     */
    public function __construct($CORE, $backendId) {
        $this->_CORE = $CORE;
        $this->_backendId = $backendId;

        $this->_dbname = cfg('backend_'.$backendId, 'dbname');
        $this->_dbuser = cfg('backend_'.$backendId, 'dbuser');
        $this->_dbpass = cfg('backend_'.$backendId, 'dbpass');
        $this->_dbhost = cfg('backend_'.$backendId, 'dbhost');
        $this->_dbport = cfg('backend_'.$backendId, 'dbport');
        $this->_dbinstancename = cfg('backend_'.$backendId, 'dbinstancename');

        $this->connectToDb();
        $this->loadInstanceId();
    }

    /**
     * Return the valid config for this backend
     *
     * @author Maximilien Bersoult <mbersoult@merethis.com>
     * @return array
     */
    static public function getValidConfig() {
        return self::$_validConfig;
    }

    /**
     * Get the list of objects
     *
     * @author Maximilien Bersoult <mbersoult@merethis.com>
     * @param string $type The object type
     * @param string $name1Pattern The object name (host name or hostgroup name or servicegroup name)
     * @param string $name2Pattern Service name for a object type service
     * @return array
     * @throws BackendException *
     */
    public function getObjects($type, $name1Pattern = '', $name2Pattern = '') {
        $ret = array();
        switch ($type) {
            case 'host':
                $queryGetObject = 'SELECT host_id, 0 as service_id, name as name1, "" as name2
                    FROM hosts
                    WHERE enabled = 1';
                if ($name1Pattern != '') {
                    $queryGetObject .= ' AND name = %s';
                }
                break;
            case 'service':
                $queryGetObject = 'SELECT s.host_id, s.service_id, h.name as name1, s.description as name2
                    FROM services s, hosts h
                    WHERE h.enabled =1
                        AND s.enabled = 1
                        AND h.name = %s
                        AND h.host_id = s.host_id';
                if ('' !== $name2Pattern) {
                    $queryGetObject .= ' AND s.description = %s';
                }
                break;
            case 'hostgroup':
                $queryGetObject = 'SELECT 0 as host_id, 0 as service_id, name as name1, "" as name2
                    FROM hostgroups
                    WHERE 1 = 1';
                if ($name1Pattern != '') {
                    $queryGetObject .= ' AND name = %s';
                }
                break;
            case 'servicegroup':
                $queryGetObject = 'SELECT 0 as host_id, 0 as service_id, name as name1, "" as name2
                    FROM servicegroups
                    WHERE 1 = 1';
                if ($name1Pattern != '') {
                    $queryGetObject .= ' name = %s';
                }
                break;
            default:
                return array();
        }
        /* Add instance id, enabled and order */
        if ($this->_instanceId != 0) {
             $queryGetObject .= ' AND instance_id = ' . $this->_instanceId;
        }
        $queryGetObject .= ' ORDER BY name1, name2';

        if ('' !== $name2Pattern) {
            $queryGetObject = sprintf($queryGetObject, $this->_dbh->quote($name1Pattern), $this->_dbh->quote($name2Pattern), $this->_instanceId);
        }
        if ('' !== $name1Pattern) {
            $queryGetObject = sprintf($queryGetObject, $this->_dbh->quote($name1Pattern), $this->_instanceId);
        }

        try {
            $stmt = $this->_dbh->query($queryGetObject);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingObject', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            /* Set cache */
            if (0 != $row['host_id']) {
                $this->_cacheHostId[$row['name1']] = $row['host_id'];
                if (0 != $row['service_id']) {
                    $this->_cacheServiceId[$row['name1']][$row['name2']] = $row['service_id'];
                }
            }
            /* Set table */
            $ret[] = array('name1' => $row['name1'], 'name2' => $row['name2']);
        }

        return $ret;
    }

    /**
     * Get host state object
     *
     * @param type $objects
     * @param type $options
     * @param type $filters
     * @return array
     */
    public function getHostState($objects, $options, $filters) {
        $queryGetHostState = 'SELECT
            h.alias,
            h.name,
            h.address,
            h.statusmap_image,
            h.notes,
            h.check_command,
            h.perfdata,
            h.last_check,
            h.next_check,
            h.state_type,
            h.check_attempt as current_check_attempt,
            h.max_check_attempts,
            h.last_state_change,
            h.last_hard_state_change,
            h.checked as has_been_checked,
            h.state as current_state,
            h.output,
            h.acknowledged as problem_has_been_acknowledged,
            d.start_time as downtime_start,
            d.end_time as downtime_end,
            d.author as downtime_author,
            d.comment_data as downtime_data
            FROM hosts h
            LEFT JOIN (
                select max(d.downtime_id) as downtime_id, d.start_time, d.end_time, d.host_id, d.author, d.comment_data
                from downtimes d where d.start_time < UNIX_TIMESTAMP() AND d.end_time > UNIX_TIMESTAMP() AND d.deletion_time IS NULL AND d.service_id IS NULL group by d.host_id ) as d 
                on d.host_id=h.host_id
            WHERE h.enabled = 1 AND (%s)';
        if ($this->_instanceId != 0) {
            $queryGetHostState .= ' AND h.instance_id = ' . $this->_instanceId;
        }
        $queryGetHostState = sprintf($queryGetHostState, $this->parseFilter($objects, $filters));

        try {
            $stmt = $this->_dbh->query($queryGetHostState);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingHostState', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        $listStates = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            /* Modifiy for downtime */
            if (false === isset($row['downtime_start']) || '' === $row['downtime_start']) {
                unset($row['downtime_start']);
                unset($row['downtime_end']);
                unset($row['downtime_author']);
                unset($row['downtime_data']);
            } else {
                $row['in_downtime'] = 1;
            }
            /* Modify state */
            /* Only Hard */
            if ($options & 1) {
                if ($row['state_type'] == '0') {
                    $row['current_state'] = $row['last_hard_state'];
                }
            }
            /* Unchecked state */
            if ($row['has_been_checked'] == '0' || $row['current_state'] == '') {
                $row['state'] = 'UNCHECKED';
                $row['output'] = l('hostIsPending', Array('HOST' => $row['name']));
            } else {
                switch ($row['current_state']) {
                    case '0':
                        $row['state'] = 'UP';
                        unset($row['problem_has_been_acknowledged']);
                        break;
                    case '1':
                        $row['state'] = 'DOWN';
                        break;
                    case '2':
                        $row['state'] = 'UNREACHABLE';
                        break;
                    case '3':
                        $row['state'] = 'UNKNOWN';
                        break;
                    default:
                        $row['state'] = 'UNKNOWN';
                        $row['output'] = 'GlobalBackendcentreonbroker::getHostState: Undefined state!';
                        break;
                }
            }
            $listStates[$row['name']] = $row;
        }
        return $listStates;
    }

    public function getServiceState($objects, $options, $filters) {
        $queryGetServiceState = 'SELECT
            h.host_id,
            h.name,
            h.address,
            s.checked as has_been_checked,
            s.description as service_description,
            s.display_name,
            s.display_name as alias,
            s.notes,
            s.check_command,
            s.perfdata,
            s.output,
            s.state as current_state,
            s.last_check,
            s.next_check,
            s.state_type,
            s.check_attempt as current_check_attempt,
            s.max_check_attempts,
            s.last_state_change,
            s.last_hard_state_change,
            s.acknowledged as problem_has_been_acknowledged,
            d.start_time as downtime_start,
            d.end_time as downtime_end,
            d.author as downtime_author,
            d.comment_data as downtime_data
            FROM services s
            LEFT JOIN hosts h
                ON s.host_id=h.host_id
            LEFT JOIN (
                select max(d.downtime_id) as downtime_id, d.start_time, d.end_time, d.service_id, d.author, d.comment_data
                from downtimes d where d.start_time < UNIX_TIMESTAMP() AND d.end_time > UNIX_TIMESTAMP() AND d.deletion_time IS NULL 
                group by d.service_id ) as d 
                on d.service_id=s.service_id
            WHERE s.host_id = h.host_id AND s.enabled = 1 AND h.enabled = 1
                AND (%s)';
        if ($this->_instanceId != 0) {
            $queryGetServiceState .= ' AND h.instance_id = ' . $this->_instanceId;
        }
        $queryGetServiceState = sprintf($queryGetServiceState, $this->parseFilter($objects, $filters));

        try {
            $stmt = $this->_dbh->query($queryGetServiceState);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingServiceState', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        $listStates = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            /* Define key */
            $specific = false;
            $key = $row['name'];
            if (isset($objects[$key . '~~' . $row['service_description']])) {
                $key = $key . '~~' . $row['service_description'];
                $specific = true;
            }
            /* Modifiy for downtime */
            if (false === isset($row['downtime_start']) || '' === $row['downtime_start']) {
                unset($row['downtime_start']);
                unset($row['downtime_end']);
                unset($row['downtime_author']);
                unset($row['downtime_data']);
            } else {
                $row['in_downtime'] = 1;
            }
            /* Modify state */
            /* Only Hard */
            if ($options & 1) {
                if ($row['state_type'] == '0') {
                    $row['current_state'] = $row['last_hard_state'];
                }
            }
            /* Get host ack */
            if ($row['problem_has_been_acknowledged'] != 1) {
                $row['problem_has_been_acknowledged'] = $this->getHostAckByHost($row['host_id']);
            }
            unset($row['host_id']);

            /* Unchecked state */
            if ($row['has_been_checked'] == '0' || $row['current_state'] == '') {
                $row['state'] = 'PENDING';
                $row['output'] = l('serviceNotChecked', Array('SERVICE' => $row['service_description']));
            } else {
                switch ($row['current_state']) {
                    case '0':
                        $row['state'] = 'OK';
                        unset($row['problem_has_been_acknowledged']);
                        break;
                    case '1':
                        $row['state'] = 'WARNING';
                        break;
                    case '2':
                        $row['state'] = 'CRITICAL';
                        break;
                    case '3':
                        $row['state'] = 'UNKNOWN';
                        break;
                    default:
                        $row['state'] = 'UNKNOWN';
                        $row['output'] = 'GlobalBackendcentreonbroker::getHostState: Undefined state!';
                        break;
                }
            }
            if ($specific) {
                $listStates[$key] = $row;
            } else {
                if (!isset($listStates[$key])) {
                    $listStates[$key] = array();
                }
                $listStates[$key][] = $row;
            }
        }
        return $listStates;
    }

    public function getHostStateCounts($objects, $options, $filters) {
        if($options & 1) {
            $stateAttr = 'IF((s.state_type = 0), s.last_hard_state, s.state)';
        } else {
            $stateAttr = 's.state';
        }
        $queryCount = 'SELECT
            h.name,
            h.alias,
            SUM(IF(s.checked=0,1,0)) AS pending,
            SUM(IF(('.$stateAttr.'=0 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND h.scheduled_downtime_depth=0),1,0)) AS ok,
            SUM(IF(('.$stateAttr.'=0 AND s.checked!=0 AND (s.scheduled_downtime_depth!=0 OR h.scheduled_downtime_depth!=0)),1,0)) AS ok_downtime,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND h.scheduled_downtime_depth=0 AND s.acknowledged=0 AND h.acknowledged=0),1,0)) AS warning,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND (s.scheduled_downtime_depth!=0 OR h.scheduled_downtime_depth!=0)),1,0)) AS warning_downtime,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND (s.acknowledged=1 OR h.acknowledged=1)),1,0)) AS warning_ack,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND h.scheduled_downtime_depth=0) AND s.acknowledged=0 AND h.acknowledged=0,1,0)) AS critical,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND (s.scheduled_downtime_depth!=0 OR h.scheduled_downtime_depth!=0)),1,0)) AS critical_downtime,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND (s.acknowledged=1 OR h.acknowledged=1)),1,0)) AS critical_ack,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND h.scheduled_downtime_depth=0 AND s.acknowledged=0 AND h.acknowledged=0),1,0)) AS unknown,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND (s.scheduled_downtime_depth!=0 OR h.scheduled_downtime_depth!=0)),1,0)) AS unknown_downtime,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND (s.acknowledged=1 OR h.acknowledged=1)),1,0)) AS unknown_ack
            FROM hosts h, services s
            WHERE h.host_id = s.host_id AND h.enabled = 1 AND s.enabled = 1
                AND (%s)';
        if ($this->_instanceId != 0) {
            $queryCount .= ' AND h.instance_id = ' . $this->_instanceId;
        }
        $queryCount .= ' GROUP BY h.name';
        $queryCount = sprintf($queryCount, $this->parseFilter($objects, $filters));
        
        try {
            $stmt = $this->_dbh->query($queryCount);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingHostStateCount', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        $counts = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts[$row['name']] = array(
                'details' => array('alias' => $row['alias']),
                'counts' => array(
                    'UNCHECKED' => array(
                        'normal' => intval($row['pending']),
                    ),
                    'OK' => array(
                        'normal'   => intval($row['ok']),
                        'downtime' => intval($row['ok_downtime']),
                    ),
                    'WARNING' => array(
                        'normal'   => intval($row['warning']),
                        'ack'      => intval($row['warning_ack']),
                        'downtime' => intval($row['warning_downtime']),
                    ),
                    'CRITICAL' => array(
                        'normal'   => intval($row['critical']),
                        'ack'      => intval($row['critical_ack']),
                        'downtime' => intval($row['critical_downtime']),
                    ),
                    'UNKNOWN' => array(
                        'normal'   => intval($row['unknown']),
                        'ack'      => intval($row['unknown_ack']),
                        'downtime' => intval($row['unknown_downtime']),
                    )
                )
            );
        }
        return $counts;
    }

    public function getHostgroupStateCounts($objects, $options, $filters) {
        if($options & 1) {
            $stateAttr = 'IF((h.state_type = 0), h.last_hard_state, h.state)';
        } else {
            $stateAttr = 'h.state';
        }
        $queryCount = 'SELECT
            hg.name,
            hg.alias,
            SUM(IF(h.checked=0,1,0)) AS unchecked,
            SUM(IF(('.$stateAttr.'=0 AND h.checked!=0 AND h.scheduled_downtime_depth=0),1,0)) AS up,
            SUM(IF(('.$stateAttr.'=0 AND h.checked!=0 AND h.scheduled_downtime_depth!=0),1,0)) AS up_downtime,
            SUM(IF(('.$stateAttr.'=1 AND h.checked!=0 AND h.scheduled_downtime_depth=0 AND h.acknowledged=0),1,0)) AS down,
            SUM(IF(('.$stateAttr.'=1 AND h.checked!=0 AND h.scheduled_downtime_depth!=0),1,0)) AS down_downtime,
            SUM(IF(('.$stateAttr.'=1 AND h.checked!=0 AND h.acknowledged=1),1,0)) AS down_ack,
            SUM(IF(('.$stateAttr.'=2 AND h.checked!=0 AND h.scheduled_downtime_depth=0 AND h.acknowledged=0),1,0)) AS unreachable,
            SUM(IF(('.$stateAttr.'=2 AND h.checked!=0 AND h.scheduled_downtime_depth!=0),1,0)) AS unreachable_downtime,
            SUM(IF(('.$stateAttr.'=2 AND h.checked!=0 AND h.acknowledged=1),1,0)) AS unreachable_ack,
            SUM(IF(('.$stateAttr.'=3 AND h.checked!=0 AND h.scheduled_downtime_depth=0 AND h.acknowledged=0),1,0)) AS unknown,
            SUM(IF(('.$stateAttr.'=3 AND h.checked!=0 AND h.scheduled_downtime_depth!=0),1,0)) AS unknown_downtime,
            SUM(IF(('.$stateAttr.'=3 AND h.checked!=0 AND h.acknowledged=1),1,0)) AS unknown_ack
            FROM hostgroups hg, hosts_hostgroups hhg, hosts h
            WHERE hhg.hostgroup_id = hg.hostgroup_id
                AND hhg.host_id = h.host_id 
                AND h.enabled = 1 
                AND (%s)';
        if ($this->_instanceId != 0) {
            $queryCount .= ' AND h.instance_id = ' . $this->_instanceId;
        }
        $queryCount .= ' GROUP BY hg.name';
        $queryCount = sprintf($queryCount, $this->parseFilter($objects, $filters, 'hg'));
        
        try {
            $stmt = $this->_dbh->query($queryCount);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettinggetHostgroupStateCounts', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        $counts = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts[$row['name']] = array(
                'details' => array('alias' => $row['alias']),
                'counts' => array(
                    'UNCHECKED' => array(
                        'normal'    => intval($row['unchecked']),
                    ),
                    'UP' => array(
                        'normal'    => intval($row['up']),
                        'downtime'  => intval($row['up_downtime']),
                    ),
                    'DOWN' => array(
                        'normal'    => intval($row['down']),
                        'ack'       => intval($row['down_ack']),
                        'downtime'  => intval($row['down_downtime']),
                    ),
                    'UNREACHABLE' => array(
                        'normal'    => intval($row['unreachable']),
                        'ack'       => intval($row['unreachable_ack']),
                        'downtime'  => intval($row['unreachable_downtime']),
                    )
                )
            );
        }
        if ($options & 2) {
            return $counts;
        }

        if ($options & 1) {
            $stateAttr = 'IF((s.state_type = 0), s.last_hard_state, s.state)';
        } else {
            $stateAttr = 's.state';
        }
        $queryCount = 'SELECT
            hg.name,
            hg.alias,
            SUM(IF(s.checked=0,1,0)) AS pending,
            SUM(IF(('.$stateAttr.'=0 AND s.checked!=0 AND s.scheduled_downtime_depth=0),1,0)) AS ok,
            SUM(IF(('.$stateAttr.'=0 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS ok_downtime,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND s.acknowledged=0),1,0)) AS warning,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS warning_downtime,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND s.acknowledged=1),1,0)) AS warning_ack,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND s.acknowledged=0),1,0)) AS critical,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS critical_downtime,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND s.acknowledged=1),1,0)) AS critical_ack,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND s.acknowledged=0),1,0)) AS unknown,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS unknown_downtime,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND s.acknowledged=1),1,0)) AS unknown_ack
            FROM hostgroups hg, hosts_hostgroups hhg, services s, hosts h
            WHERE hhg.hostgroup_id = hg.hostgroup_id
                AND hhg.host_id = s.host_id
                AND hhg.host_id = h.host_id
                AND h.enabled = 1
                AND s.enabled = 1
                AND (%s)';
        if ($this->_instanceId != 0) {
            $queryCount .= ' AND h.instance_id = ' . $this->_instanceId;
        }
        $queryCount .= ' GROUP BY hg.name';
        $queryCount = sprintf($queryCount, $this->parseFilter($objects, $filters, 'hg'));
        
        try {
            $stmt = $this->_dbh->query($queryCount);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettinggetHostgroupStateCounts', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts[$row['name']]['counts']['PENDING']['normal']    = intval($row['pending']);
            $counts[$row['name']]['counts']['OK']['normal']         = intval($row['ok']);
            $counts[$row['name']]['counts']['OK']['downtime']       = intval($row['ok_downtime']);
            $counts[$row['name']]['counts']['WARNING']['normal']    = intval($row['warning']);
            $counts[$row['name']]['counts']['WARNING']['ack']       = intval($row['warning_ack']);
            $counts[$row['name']]['counts']['WARNING']['downtime']  = intval($row['warning_downtime']);
            $counts[$row['name']]['counts']['CRITICAL']['normal']   = intval($row['critical']);
            $counts[$row['name']]['counts']['CRITICAL']['ack']      = intval($row['critical_ack']);
            $counts[$row['name']]['counts']['CRITICAL']['downtime'] = intval($row['critical_downtime']);
            $counts[$row['name']]['counts']['UNKNOWN']['normal']    = intval($row['unknown']);
            $counts[$row['name']]['counts']['UNKNOWN']['ack']       = intval($row['unknown_ack']);
            $counts[$row['name']]['counts']['UNKNOWN']['downtime']  = intval($row['unknown_downtime']);
        }
        return $counts;
    }

    public function getServicegroupStateCounts($objects, $options, $filters) {
        if($options & 1) {
            $stateAttr = 'IF((s.state_type = 0), s.last_hard_state, s.state)';
        } else {
            $stateAttr = 's.state';
        }
        $queryCount = 'SELECT
            sg.name,
            sg.alias,
            SUM(IF(s.checked=0,1,0)) AS pending,
            SUM(IF(('.$stateAttr.'=0 AND s.checked!=0 AND s.scheduled_downtime_depth=0),1,0)) AS ok,
            SUM(IF(('.$stateAttr.'=0 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS ok_downtime,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND s.acknowledged=0),1,0)) AS warning,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS warning_downtime,
            SUM(IF(('.$stateAttr.'=1 AND s.checked!=0 AND s.acknowledged=1),1,0)) AS warning_ack,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND s.acknowledged=0),1,0)) AS critical,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS critical_downtime,
            SUM(IF(('.$stateAttr.'=2 AND s.checked!=0 AND s.acknowledged=1),1,0)) AS critical_ack,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND s.scheduled_downtime_depth=0 AND s.acknowledged=0),1,0)) AS unknown,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND s.scheduled_downtime_depth!=0),1,0)) AS unknown_downtime,
            SUM(IF(('.$stateAttr.'=3 AND s.checked!=0 AND s.acknowledged=1),1,0)) AS unknown_ack
            FROM servicegroups sg, services_servicegroups ssg, services s
            WHERE ssg.servicegroup_id = sg.servicegroup_id
                AND ssg.service_id = s.service_id
                AND s.enabled = 1
                AND (%s) GROUP BY sg.name';
        $queryCount = sprintf($queryCount, $this->parseFilter($objects, $filters, 'sg'));

        try {
            $stmt = $this->_dbh->query($queryCount);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingServicegroupStateCounts', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        $counts = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts[$row['name']] = array(
                'details' => array('alias' => $row['alias']),
                'counts' => array(
                    'PENDING' => array(
                        'normal'   => intval($row['pending']),
                    ),
                    'OK' => array(
                        'normal'   => intval($row['ok']),
                        'downtime' => intval($row['ok_downtime']),
                    ),
                    'WARNING' => array(
                        'normal'   => intval($row['warning']),
                        'ack'      => intval($row['warning_ack']),
                        'downtime' => intval($row['warning_downtime']),
                    ),
                    'CRITICAL' => array(
                        'normal'   => intval($row['critical']),
                        'ack'      => intval($row['critical_ack']),
                        'downtime' => intval($row['critical_downtime']),
                    ),
                    'UNKNOWN' => array(
                        'normal'   => intval($row['unknown']),
                        'ack'      => intval($row['unknown_ack']),
                        'downtime' => intval($row['unknown_downtime']),
                    )
                )
            );
        }

        return $counts;
    }

    public function getHostNamesWithNoParent() {
        $queryNoParents = 'SELECT name
            FROM hosts
            WHERE enabled = 1 AND host_id NOT IN (SELECT host_id
                    FROM hosts_hosts_parents)';
        if ($this->_instanceId != 0) {
            $queryNoParents .= ' AND instance_id = ' . $this->_instanceId;
        }
        
        try {
            $stmt = $this->_dbh->query($queryNoParents);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingHostNamesWithNoParent', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        $noParents = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $noParents[] = $row['name'];
        }
        return $noParents;
    }

    public function getDirectChildNamesByHostName($hostname) {
        $queryGetChilds = 'SELECT h.name
            FROM hosts h, hosts_hosts_parents hp
            WHERE h.host_id = hp.child_id
                AND h.enabled = 1
                AND hp.parent_id IN (SELECT host_id
                    FROM hosts
                    WHERE name = %s)';
        if ($this->_instanceId != 0) {
            $queryGetChilds .= ' AND h.instance_id = ' . $this->_instanceId;
        }
        $queryGetChilds = sprintf($queryGetChilds, $this->_dbh->quote($hostname));
        
        try {
            $stmt = $this->_dbh->query($queryGetChilds);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingDirectChildNamesByHostName', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        $childs = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $childs[] = $row['name'];
        }
        return $childs;
    }

    public function getDirectParentNamesByHostName($hostname) {
        $queryGetParents = 'SELECT h.name
            FROM hosts h, hosts_hosts_parents hp
            WHERE h.host_id = hp.parent_id
                AND h.enabled = 1
                AND hp.child_id IN (SELECT host_id
                    FROM hosts
                    WHERE name = "%s")';
        if ($this->_instanceId != 0) {
            $queryGetParents .= ' AND h.instance_id = ' . $this->_instanceId;
        }
        $queryGetParents = sprintf($queryGetParents, $this->_dbh->quote($hostname));
        
        try {
            $stmt = $this->_dbh->query($queryGetParents);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingDirectParentNamesByHostName', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }

        $parents = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $parents[] = $row['name'];
        }
        return $parents;
    }

    private function getHostAckByHost($hostId) {
        if (isset($this->_cacheHostAck[$hostId])) {
            return $this->_cacheHostAck[$hostId];
        }
        $queryAck = 'SELECT acknowledged
            FROM hosts
            WHERE enabled = 1 AND host_id = ' . $hostId;
            
        try {
            $stmt = $this->_dbh->query($queryAck);
        } catch (PDOException $e) {
            throw new BackendException(l('errorGettingHostAck', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage())));
        }    

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (false === $row) {
            return 0;
        }
        
        $return = 0;
        if (isset($row['acknowledged']) && $row['acknowledged'] == '1') {
            $return = 1;
        }
        $this->_cacheHostAck[$hostId] = $return;
        return $this->_cacheHostAck[$hostId];
    }

    private function parseFilter($objects, $filters, $tableAlias = 'h') {
        $listKeys = array(
            'host_name',
            'host_groups',
            'service_groups',
            'hostgroup_name',
            'group_name',
            'groups',
            'servicegroup_name',
            'service_description'
        );
        $allFilters = array();
        foreach ($objects as $object) {
            $objFilters = array();
            /* Filters */
            foreach ($filters as $filter) {
                if (false === in_array($filter['key'], $listKeys)) {
                    throw new BackendException('Invalid filter key ('.$filter['key'].')');
                }
                if ($filter['op'] == '>=') {
                    $op = '=';
                } else {
                    $op = $filter['op'];
                }
                if ($filter['key'] == 'service_description') {
                    $key = 's.description';
                    $val = $object[0]->getServiceDescription();
                } else {
                    $key = $tableAlias . '.name';
                    $val = $object[0]->getName();
                }
                $objFilters[] = $key . ' ' . $op . ' ' . $this->_dbh->quote($val);
            }


            $allFilters[] = join(' AND ', $objFilters);
        }
        return join(' OR ', $allFilters);
    }

    /**
     * Connection to the Centreon Broker database
     *
     * @author Maximilien Bersoult <mbersoult@merethis.com>
     * @throws BackendConnectionProblem
     */
    private function connectToDb() {
        if (false === extension_loaded('mysql')) {
            throw new BackendConnectionProblem(l('mysqlNotSupported', array('BACKENDID', $this->_backendId)));
        }
        $fullhost = 'host=' . $this->_dbhost;
        if ('' != $this->_dbport) {
            $fullhost .= ';port=' . $this->_dbport;
        }
        if ('' != $this->_dbname) {
            $fullhost .= ';dbname=' . $this->_dbname;
        }
        try {
            $this->_dbh = new PDO('mysql:' . $fullhost, $this->_dbuser, $this->_dbpass, array(PDO::ATTR_PERSISTENT => false));
            $this->_dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw new BackendConnectionProblem(l('errorConnectingMySQL', Array('BACKENDID' => $this->backendId,'MYSQLERR' => $e->getMessage())));
        }
    }

    /**
     * Load the instance id
     *
     * @author Maximilien Bersoult <mbersoult@merethis.com>
     * @throws BackendException
     */
    private function loadInstanceId() {
        try {
            $stmt = $this->_dbh->prepare("SELECT instance_id
                            FROM instances
                            WHERE name = :name");
            $stmt->bindParam(':name', $this->_dbinstancename, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e ) {
            throw new BackendException('errorLoadingInstanceId', array('BACKENDID' => $this->_backendId, 'ERROR' => $e->getMessage()));
        }

        if (isset($row['instance_id'])) {
            $this->_instanceId = $row['instance_id'];
        }
    }
}

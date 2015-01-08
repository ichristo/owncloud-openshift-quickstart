<?php

/**
 * ownCloud – LDAP Wizard
 *
 * @author Arthur Schiwon
 * @copyright 2013 Arthur Schiwon blizzz@owncloud.com
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\user_ldap\lib;

class Wizard extends LDAPUtility {
	static protected $l;
	protected $access;
	protected $cr;
	protected $configuration;
	protected $result;
	protected $resultCache = array();

	const LRESULT_PROCESSED_OK = 2;
	const LRESULT_PROCESSED_INVALID = 3;
	const LRESULT_PROCESSED_SKIP = 4;

	const LFILTER_LOGIN      = 2;
	const LFILTER_USER_LIST  = 3;
	const LFILTER_GROUP_LIST = 4;

	const LFILTER_MODE_ASSISTED = 2;
	const LFILTER_MODE_RAW = 1;

	const LDAP_NW_TIMEOUT = 4;

	/**
	 * Constructor
	 * @param Configuration $configuration an instance of Configuration
	 * @param ILDAPWrapper $ldap an instance of ILDAPWrapper
	 */
	public function __construct(Configuration $configuration, ILDAPWrapper $ldap, Access $access) {
		parent::__construct($ldap);
		$this->configuration = $configuration;
		if(is_null(Wizard::$l)) {
			Wizard::$l = \OC_L10N::get('user_ldap');
		}
		$this->access = $access;
		$this->result = new WizardResult;
	}

	public function  __destruct() {
		if($this->result->hasChanges()) {
			$this->configuration->saveConfiguration();
		}
	}

	/**
	 * counts entries in the LDAP directory
	 * @param string $filter the LDAP search filter
	 * @param string $type a string being either 'users' or 'groups';
	 * @return int|bool
	 */
	public function countEntries($filter, $type) {
		$reqs = array('ldapHost', 'ldapPort', 'ldapBase');
		if($type === 'users') {
			$reqs[] = 'ldapUserFilter';
		}
		if(!$this->checkRequirements($reqs)) {
			throw new \Exception('Requirements not met', 400);
		}

		if($type === 'groups') {
			$result =  $this->access->countGroups($filter);
		} else if($type === 'users') {
			$result = $this->access->countUsers($filter);
		} else {
			throw new \Exception('internal error: invald object type', 500);
		}

		return $result;
	}

	public function countGroups() {
		$filter = $this->configuration->ldapGroupFilter;

		if(empty($filter)) {
			$output = self::$l->n('%s group found', '%s groups found', 0, array(0));
			$this->result->addChange('ldap_group_count', $output);
			return $this->result;
		}

		try {
			$groupsTotal = $this->countEntries($filter, 'groups');
		} catch (\Exception $e) {
			//400 can be ignored, 500 is forwarded
			if($e->getCode() === 500) {
				throw $e;
			}
			return false;
		}
		$groupsTotal = ($groupsTotal !== false) ? $groupsTotal : 0;
		$output = self::$l->n('%s group found', '%s groups found', $groupsTotal, $groupsTotal);
		$this->result->addChange('ldap_group_count', $output);
		return $this->result;
	}

	/**
	 * @return WizardResult
	 * @throws \Exception
	 */
	public function countUsers() {
		$filter = $this->configuration->ldapUserFilter;

		$usersTotal = $this->countEntries($filter, 'users');
		$usersTotal = ($usersTotal !== false) ? $usersTotal : 0;
		$output = self::$l->n('%s user found', '%s users found', $usersTotal, $usersTotal);
		$this->result->addChange('ldap_user_count', $output);
		return $this->result;
	}

	/**
	 * counts users with a specified attribute
	 * @param string $attr
	 * @return int|bool
	 */
	public function countUsersWithAttribute($attr) {
		if(!$this->checkRequirements(array('ldapHost',
										   'ldapPort',
										   'ldapBase',
										   'ldapUserFilter',
										   ))) {
			return  false;
		}

		$filter = $this->access->combineFilterWithAnd(array(
			$this->configuration->ldapUserFilter,
			$attr . '=*'
		));

		return $this->access->countUsers($filter);
	}

	/**
	 * detects the most often used email attribute for users applying to the
	 * user list filter. If a setting is already present that returns at least
	 * one hit, the detection will be canceled.
	 * @return WizardResult|bool
	 */
	public function detectEmailAttribute() {
		if(!$this->checkRequirements(array('ldapHost',
										   'ldapPort',
										   'ldapBase',
										   'ldapUserFilter',
										   ))) {
			return  false;
		}

		$attr = $this->configuration->ldapEmailAttribute;
		if(!empty($attr)) {
			$count = intval($this->countUsersWithAttribute($attr));
			if($count > 0) {
				return false;
			}
			$writeLog = true;
		} else {
			$writeLog = false;
		}

		$emailAttributes = array('mail', 'mailPrimaryAddress');
		$winner = '';
		$maxUsers = 0;
		foreach($emailAttributes as $attr) {
			$count = $this->countUsersWithAttribute($attr);
			if($count > $maxUsers) {
				$maxUsers = $count;
				$winner = $attr;
			}
		}

		if($winner !== '') {
			$this->result->addChange('ldap_email_attr', $winner);
			if($writeLog) {
				\OCP\Util::writeLog('user_ldap', 'The mail attribute has ' .
					'automatically been reset, because the original value ' .
					'did not return any results.', \OCP\Util::INFO);
			}
		}

		return $this->result;
	}

	/**
	 * @return WizardResult
	 * @throws \Exception
	 */
	public function determineAttributes() {
		if(!$this->checkRequirements(array('ldapHost',
										   'ldapPort',
										   'ldapBase',
										   'ldapUserFilter',
										   ))) {
			return  false;
		}

		$attributes = $this->getUserAttributes();

		natcasesort($attributes);
		$attributes = array_values($attributes);

		$this->result->addOptions('ldap_loginfilter_attributes', $attributes);

		$selected = $this->configuration->ldapLoginFilterAttributes;
		if(is_array($selected) && !empty($selected)) {
			$this->result->addChange('ldap_loginfilter_attributes', $selected);
		}

		return $this->result;
	}

	/**
	 * return the state of the Group Filter Mode
	 * @return WizardResult
	 */
	public function getGroupFilterMode() {
		$this->getFilterMode('ldapGroupFilterMode');
		return $this->result;
	}

	/**
	 * return the state of the Login Filter Mode
	 * @return WizardResult
	 */
	public function getLoginFilterMode() {
		$this->getFilterMode('ldapLoginFilterMode');
		return $this->result;
	}

	/**
	 * return the state of the User Filter Mode
	 * @return WizardResult
	 */
	public function getUserFilterMode() {
		$this->getFilterMode('ldapUserFilterMode');
		return $this->result;
	}

	/**
	 * return the state of the mode of the specified filter
	 * @param string $confKey contains the access key of the Configuration
	 */
	private function getFilterMode($confKey) {
		$mode = $this->configuration->$confKey;
		if(is_null($mode)) {
			$mode = $this->LFILTER_MODE_ASSISTED;
		}
		$this->result->addChange($confKey, $mode);
	}

	/**
	 * detects the available LDAP attributes
	 * @return array The instance's WizardResult instance
	 * @throws \Exception
	 */
	private function getUserAttributes() {
		if(!$this->checkRequirements(array('ldapHost',
										   'ldapPort',
										   'ldapBase',
										   'ldapUserFilter',
										   ))) {
			return  false;
		}
		$cr = $this->getConnection();
		if(!$cr) {
			throw new \Exception('Could not connect to LDAP');
		}

		$base = $this->configuration->ldapBase[0];
		$filter = $this->configuration->ldapUserFilter;
		$rr = $this->ldap->search($cr, $base, $filter, array(), 1, 1);
		if(!$this->ldap->isResource($rr)) {
			return false;
		}
		$er = $this->ldap->firstEntry($cr, $rr);
		$attributes = $this->ldap->getAttributes($cr, $er);
		$pureAttributes = array();
		for($i = 0; $i < $attributes['count']; $i++) {
			$pureAttributes[] = $attributes[$i];
		}

		return $pureAttributes;
	}

	/**
	 * detects the available LDAP groups
	 * @return WizardResult the instance's WizardResult instance
	 */
	public function determineGroupsForGroups() {
		return $this->determineGroups('ldap_groupfilter_groups',
									  'ldapGroupFilterGroups',
									  false);
	}

	/**
	 * detects the available LDAP groups
	 * @return WizardResult the instance's WizardResult instance
	 */
	public function determineGroupsForUsers() {
		return $this->determineGroups('ldap_userfilter_groups',
									  'ldapUserFilterGroups');
	}

	/**
	 * detects the available LDAP groups
	 * @param string $dbKey
	 * @param string $confKey
	 * @param bool $testMemberOf
	 * @return WizardResult the instance's WizardResult instance
	 * @throws \Exception
	 */
	private function determineGroups($dbKey, $confKey, $testMemberOf = true) {
		if(!$this->checkRequirements(array('ldapHost',
										   'ldapPort',
										   'ldapBase',
										   ))) {
			return  false;
		}
		$cr = $this->getConnection();
		if(!$cr) {
			throw new \Exception('Could not connect to LDAP');
		}

		$groups = $this->fetchGroups($dbKey, $confKey);

		if($testMemberOf) {
			$this->configuration->hasMemberOfFilterSupport = $this->testMemberOf($groups);
			$this->result->markChange();
			if(!$this->configuration->hasMemberOfFilterSupport) {
				throw new \Exception('memberOf is not supported by the server');
			}
		}

		return $this->result;
	}

	/**
	 * fetches all groups from LDAP
	 * @param string $dbKey
	 * @param string $confKey
	 * @return array $groupEntries
	 */
	public function fetchGroups($dbKey, $confKey) {
		$obclasses = array('posixGroup', 'group', 'zimbraDistributionList', 'groupOfNames');

		$filterParts = array();
		foreach($obclasses as $obclass) {
			$filterParts[] = 'objectclass='.$obclass;
		}
		//we filter for everything
		//- that looks like a group and
		//- has the group display name set
		$filter = $this->access->combineFilterWithOr($filterParts);
		$filter = $this->access->combineFilterWithAnd(array($filter, 'cn=*'));

		$groupNames = array();
		$groupEntries = array();
		$limit = 400;
		$offset = 0;
		do {
			// we need to request dn additionally here, otherwise memberOf
			// detection will fail later
			$result = $this->access->searchGroups($filter, array('cn', 'dn'), $limit, $offset);
			foreach($result as $item) {
				$groupNames[] = $item['cn'];
				$groupEntries[] = $item;
			}
			$offset += $limit;
		} while (count($groupNames) > 0 && count($groupNames) % $limit === 0);

		if(count($groupNames) > 0) {
			natsort($groupNames);
			$this->result->addOptions($dbKey, array_values($groupNames));
		} else {
			throw new \Exception(self::$l->t('Could not find the desired feature'));
		}

		$setFeatures = $this->configuration->$confKey;
		if(is_array($setFeatures) && !empty($setFeatures)) {
			//something is already configured? pre-select it.
			$this->result->addChange($dbKey, $setFeatures);
		}
		return $groupEntries;
	}

	public function determineGroupMemberAssoc() {
		if(!$this->checkRequirements(array('ldapHost',
										   'ldapPort',
										   'ldapGroupFilter',
										   ))) {
			return  false;
		}
		$attribute = $this->detectGroupMemberAssoc();
		if($attribute === false) {
			return false;
		}
		$this->configuration->setConfiguration(array('ldapGroupMemberAssocAttr' => $attribute));
		//so it will be saved on destruct
		$this->result->markChange();

		return $this->result;
	}

	/**
	 * Detects the available object classes
	 * @return WizardResult the instance's WizardResult instance
	 * @throws \Exception
	 */
	public function determineGroupObjectClasses() {
		if(!$this->checkRequirements(array('ldapHost',
										   'ldapPort',
										   'ldapBase',
										   ))) {
			return  false;
		}
		$cr = $this->getConnection();
		if(!$cr) {
			throw new \Exception('Could not connect to LDAP');
		}

		$obclasses = array('group', 'posixGroup', '*');
		$this->determineFeature($obclasses,
								'objectclass',
								'ldap_groupfilter_objectclass',
								'ldapGroupFilterObjectclass',
								false);

		return $this->result;
	}

	/**
	 * detects the available object classes
	 * @return WizardResult
	 * @throws \Exception
	 */
	public function determineUserObjectClasses() {
		if(!$this->checkRequirements(array('ldapHost',
										   'ldapPort',
										   'ldapBase',
										   ))) {
			return  false;
		}
		$cr = $this->getConnection();
		if(!$cr) {
			throw new \Exception('Could not connect to LDAP');
		}

		$obclasses = array('inetOrgPerson', 'person', 'organizationalPerson',
						   'user', 'posixAccount', '*');
		$filter = $this->configuration->ldapUserFilter;
		//if filter is empty, it is probably the first time the wizard is called
		//then, apply suggestions.
		$this->determineFeature($obclasses,
								'objectclass',
								'ldap_userfilter_objectclass',
								'ldapUserFilterObjectclass',
								empty($filter));

		return $this->result;
	}

	/**
	 * @return WizardResult
	 * @throws \Exception
	 */
	public function getGroupFilter() {
		if(!$this->checkRequirements(array('ldapHost',
										   'ldapPort',
										   'ldapBase',
										   ))) {
			return false;
		}
		//make sure the use display name is set
		$displayName = $this->configuration->ldapGroupDisplayName;
		if(empty($displayName)) {
			$d = $this->configuration->getDefaults();
			$this->applyFind('ldap_group_display_name',
							 $d['ldap_group_display_name']);
		}
		$filter = $this->composeLdapFilter(self::LFILTER_GROUP_LIST);

		$this->applyFind('ldap_group_filter', $filter);
		return $this->result;
	}

	/**
	 * @return WizardResult
	 * @throws \Exception
	 */
	public function getUserListFilter() {
		if(!$this->checkRequirements(array('ldapHost',
										   'ldapPort',
										   'ldapBase',
										   ))) {
			return false;
		}
		//make sure the use display name is set
		$displayName = $this->configuration->ldapUserDisplayName;
		if(empty($displayName)) {
			$d = $this->configuration->getDefaults();
			$this->applyFind('ldap_display_name', $d['ldap_display_name']);
		}
		$filter = $this->composeLdapFilter(self::LFILTER_USER_LIST);
		if(!$filter) {
			throw new \Exception('Cannot create filter');
		}

		$this->applyFind('ldap_userlist_filter', $filter);
		return $this->result;
	}

	/**
	 * @return bool|WizardResult
	 * @throws \Exception
	 */
	public function getUserLoginFilter() {
		if(!$this->checkRequirements(array('ldapHost',
										   'ldapPort',
										   'ldapBase',
										   'ldapUserFilter',
										   ))) {
			return false;
		}

		$filter = $this->composeLdapFilter(self::LFILTER_LOGIN);
		if(!$filter) {
			throw new \Exception('Cannot create filter');
		}

		$this->applyFind('ldap_login_filter', $filter);
		return $this->result;
	}

	/**
	 * Tries to determine the port, requires given Host, User DN and Password
	 * @return WizardResult|false WizardResult on success, false otherwise
	 * @throws \Exception
	 */
	public function guessPortAndTLS() {
		if(!$this->checkRequirements(array('ldapHost',
										   ))) {
			return false;
		}
		$this->checkHost();
		$portSettings = $this->getPortSettingsToTry();

		if(!is_array($portSettings)) {
			throw new \Exception(print_r($portSettings, true));
		}

		//proceed from the best configuration and return on first success
		foreach($portSettings as $setting) {
			$p = $setting['port'];
			$t = $setting['tls'];
			\OCP\Util::writeLog('user_ldap', 'Wiz: trying port '. $p . ', TLS '. $t, \OCP\Util::DEBUG);
			//connectAndBind may throw Exception, it needs to be catched by the
			//callee of this method
			if($this->connectAndBind($p, $t) === true) {
				$config = array('ldapPort' => $p,
								'ldapTLS'  => intval($t)
							);
				$this->configuration->setConfiguration($config);
				\OCP\Util::writeLog('user_ldap', 'Wiz: detected Port '. $p, \OCP\Util::DEBUG);
				$this->result->addChange('ldap_port', $p);
				return $this->result;
			}
		}

		//custom port, undetected (we do not brute force)
		return false;
	}

	/**
	 * tries to determine a base dn from User DN or LDAP Host
	 * @return WizardResult|false WizardResult on success, false otherwise
	 */
	public function guessBaseDN() {
		if(!$this->checkRequirements(array('ldapHost',
										   'ldapPort',
										   ))) {
			return false;
		}

		//check whether a DN is given in the agent name (99.9% of all cases)
		$base = null;
		$i = stripos($this->configuration->ldapAgentName, 'dc=');
		if($i !== false) {
			$base = substr($this->configuration->ldapAgentName, $i);
			if($this->testBaseDN($base)) {
				$this->applyFind('ldap_base', $base);
				return $this->result;
			}
		}

		//this did not help :(
		//Let's see whether we can parse the Host URL and convert the domain to
		//a base DN
		$domain = Helper::getDomainFromURL($this->configuration->ldapHost);
		if(!$domain) {
			return false;
		}

		$dparts = explode('.', $domain);
		$base2 = implode('dc=', $dparts);
		if($base !== $base2 && $this->testBaseDN($base2)) {
			$this->applyFind('ldap_base', $base2);
			return $this->result;
		}

		return false;
	}

	/**
	 * sets the found value for the configuration key in the WizardResult
	 * as well as in the Configuration instance
	 * @param string $key the configuration key
	 * @param string $value the (detected) value
	 *
	 */
	private function applyFind($key, $value) {
		$this->result->addChange($key, $value);
		$this->configuration->setConfiguration(array($key => $value));
	}

	/**
	 * Checks, whether a port was entered in the Host configuration
	 * field. In this case the port will be stripped off, but also stored as
	 * setting.
	 */
	private function checkHost() {
		$host = $this->configuration->ldapHost;
		$hostInfo = parse_url($host);

		//removes Port from Host
		if(is_array($hostInfo) && isset($hostInfo['port'])) {
			$port = $hostInfo['port'];
			$host = str_replace(':'.$port, '', $host);
			$this->applyFind('ldap_host', $host);
			$this->applyFind('ldap_port', $port);
		}
	}

	/**
	 * tries to detect the group member association attribute which is
	 * one of 'uniqueMember', 'memberUid', 'member'
	 * @return string|false, string with the attribute name, false on error
	 * @throws \Exception
	 */
	private function detectGroupMemberAssoc() {
		$possibleAttrs = array('uniqueMember', 'memberUid', 'member', 'unfugasdfasdfdfa');
		$filter = $this->configuration->ldapGroupFilter;
		if(empty($filter)) {
			return false;
		}
		$cr = $this->getConnection();
		if(!$cr) {
			throw new \Exception('Could not connect to LDAP');
		}
		$base = $this->configuration->ldapBase[0];
		$rr = $this->ldap->search($cr, $base, $filter, $possibleAttrs);
		if(!$this->ldap->isResource($rr)) {
			return false;
		}
		$er = $this->ldap->firstEntry($cr, $rr);
		while(is_resource($er)) {
			$this->ldap->getDN($cr, $er);
			$attrs = $this->ldap->getAttributes($cr, $er);
			$result = array();
			for($i = 0; $i < count($possibleAttrs); $i++) {
				if(isset($attrs[$possibleAttrs[$i]])) {
					$result[$possibleAttrs[$i]] = $attrs[$possibleAttrs[$i]]['count'];
				}
			}
			if(!empty($result)) {
				natsort($result);
				return key($result);
			}

			$er = $this->ldap->nextEntry($cr, $er);
		}

		return false;
	}

	/**
	 * Checks whether for a given BaseDN results will be returned
	 * @param string $base the BaseDN to test
	 * @return bool true on success, false otherwise
	 * @throws \Exception
	 */
	private function testBaseDN($base) {
		$cr = $this->getConnection();
		if(!$cr) {
			throw new \Exception('Could not connect to LDAP');
		}

		//base is there, let's validate it. If we search for anything, we should
		//get a result set > 0 on a proper base
		$rr = $this->ldap->search($cr, $base, 'objectClass=*', array('dn'), 0, 1);
		if(!$this->ldap->isResource($rr)) {
			$errorNo  = $this->ldap->errno($cr);
			$errorMsg = $this->ldap->error($cr);
			\OCP\Util::writeLog('user_ldap', 'Wiz: Could not search base '.$base.
							' Error '.$errorNo.': '.$errorMsg, \OCP\Util::INFO);
			return false;
		}
		$entries = $this->ldap->countEntries($cr, $rr);
		return ($entries !== false) && ($entries > 0);
	}

	/**
	 * Checks whether the server supports memberOf in LDAP Filter.
	 * Requires that groups are determined, thus internally called from within
	 * determineGroups()
	 * @param array $groups
	 * @return bool true if it does, false otherwise
	 * @throws \Exception
	 */
	private function testMemberOf($groups) {
		$cr = $this->getConnection();
		if(!$cr) {
			throw new \Exception('Could not connect to LDAP');
		}
		if(!is_array($this->configuration->ldapBase)
		   || !isset($this->configuration->ldapBase[0])) {
			return false;
		}
		$base = $this->configuration->ldapBase[0];
		$filterPrefix = '(&(objectclass=*)(memberOf=';
		$filterSuffix = '))';

		foreach($groups as $groupProperties) {
			if(!isset($groupProperties['cn'])) {
				//assuming only groups have their cn cached :)
				continue;
			}
			$filter = strtolower($filterPrefix . $groupProperties['dn'] . $filterSuffix);
			$rr = $this->ldap->search($cr, $base, $filter, array('dn'));
			if(!$this->ldap->isResource($rr)) {
				continue;
			}
			$entries = $this->ldap->countEntries($cr, $rr);
			//we do not know which groups are empty, so test any and return
			//success on the first match that returns at least one user
			if(($entries !== false) && ($entries > 0)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * creates an LDAP Filter from given configuration
	 * @param integer $filterType int, for which use case the filter shall be created
	 * can be any of self::LFILTER_USER_LIST, self::LFILTER_LOGIN or
	 * self::LFILTER_GROUP_LIST
	 * @return string|false string with the filter on success, false otherwise
	 * @throws \Exception
	 */
	private function composeLdapFilter($filterType) {
		$filter = '';
		$parts = 0;
		switch ($filterType) {
			case self::LFILTER_USER_LIST:
				$objcs = $this->configuration->ldapUserFilterObjectclass;
				//glue objectclasses
				if(is_array($objcs) && count($objcs) > 0) {
					$filter .= '(|';
					foreach($objcs as $objc) {
						$filter .= '(objectclass=' . $objc . ')';
					}
					$filter .= ')';
					$parts++;
				}
				//glue group memberships
				if($this->configuration->hasMemberOfFilterSupport) {
					$cns = $this->configuration->ldapUserFilterGroups;
					if(is_array($cns) && count($cns) > 0) {
						$filter .= '(|';
						$cr = $this->getConnection();
						if(!$cr) {
							throw new \Exception('Could not connect to LDAP');
						}
						$base = $this->configuration->ldapBase[0];
						foreach($cns as $cn) {
							$rr = $this->ldap->search($cr, $base, 'cn=' . $cn, array('dn'));
							if(!$this->ldap->isResource($rr)) {
								continue;
							}
							$er = $this->ldap->firstEntry($cr, $rr);
							$dn = $this->ldap->getDN($cr, $er);
							$filter .= '(memberof=' . $dn . ')';
						}
						$filter .= ')';
					}
					$parts++;
				}
				//wrap parts in AND condition
				if($parts > 1) {
					$filter = '(&' . $filter . ')';
				}
				if(empty($filter)) {
					$filter = '(objectclass=*)';
				}
				break;

			case self::LFILTER_GROUP_LIST:
				$objcs = $this->configuration->ldapGroupFilterObjectclass;
				//glue objectclasses
				if(is_array($objcs) && count($objcs) > 0) {
					$filter .= '(|';
					foreach($objcs as $objc) {
						$filter .= '(objectclass=' . $objc . ')';
					}
					$filter .= ')';
					$parts++;
				}
				//glue group memberships
				$cns = $this->configuration->ldapGroupFilterGroups;
				if(is_array($cns) && count($cns) > 0) {
					$filter .= '(|';
					$base = $this->configuration->ldapBase[0];
					foreach($cns as $cn) {
						$filter .= '(cn=' . $cn . ')';
					}
					$filter .= ')';
				}
				$parts++;
				//wrap parts in AND condition
				if($parts > 1) {
					$filter = '(&' . $filter . ')';
				}
				break;

			case self::LFILTER_LOGIN:
				$ulf = $this->configuration->ldapUserFilter;
				$loginpart = '=%uid';
				$filterUsername = '';
				$userAttributes = $this->getUserAttributes();
				$userAttributes = array_change_key_case(array_flip($userAttributes));
				$parts = 0;

				$x = $this->configuration->ldapLoginFilterUsername;
				if($this->configuration->ldapLoginFilterUsername === '1') {
					$attr = '';
					if(isset($userAttributes['uid'])) {
						$attr = 'uid';
					} else if(isset($userAttributes['samaccountname'])) {
						$attr = 'samaccountname';
					} else if(isset($userAttributes['cn'])) {
						//fallback
						$attr = 'cn';
					}
					if(!empty($attr)) {
						$filterUsername = '(' . $attr . $loginpart . ')';
						$parts++;
					}
				}

				$filterEmail = '';
				if($this->configuration->ldapLoginFilterEmail === '1') {
					$filterEmail = '(|(mailPrimaryAddress=%uid)(mail=%uid))';
					$parts++;
				}

				$filterAttributes = '';
				$attrsToFilter = $this->configuration->ldapLoginFilterAttributes;
				if(is_array($attrsToFilter) && count($attrsToFilter) > 0) {
					$filterAttributes = '(|';
					foreach($attrsToFilter as $attribute) {
						$filterAttributes .= '(' . $attribute . $loginpart . ')';
					}
					$filterAttributes .= ')';
					$parts++;
				}

				$filterLogin = '';
				if($parts > 1) {
					$filterLogin = '(|';
				}
				$filterLogin .= $filterUsername;
				$filterLogin .= $filterEmail;
				$filterLogin .= $filterAttributes;
				if($parts > 1) {
					$filterLogin .= ')';
				}

				$filter = '(&'.$ulf.$filterLogin.')';
				break;
		}

		\OCP\Util::writeLog('user_ldap', 'Wiz: Final filter '.$filter, \OCP\Util::DEBUG);

		return $filter;
	}

	/**
	 * Connects and Binds to an LDAP Server
	 * @param int $port the port to connect with
	 * @param bool $tls whether startTLS is to be used
	 * @param bool $ncc
	 * @return bool
	 * @throws \Exception
	 */
	private function connectAndBind($port = 389, $tls = false, $ncc = false) {
		if($ncc) {
			//No certificate check
			//FIXME: undo afterwards
			putenv('LDAPTLS_REQCERT=never');
		}

		//connect, does not really trigger any server communication
		\OCP\Util::writeLog('user_ldap', 'Wiz: Checking Host Info ', \OCP\Util::DEBUG);
		$host = $this->configuration->ldapHost;
		$hostInfo = parse_url($host);
		if(!$hostInfo) {
			throw new \Exception($this->l->t('Invalid Host'));
		}
		if(isset($hostInfo['scheme'])) {
			if(isset($hostInfo['port'])) {
				//problem
			} else {
				$host .= ':' . $port;
			}
		}
		\OCP\Util::writeLog('user_ldap', 'Wiz: Attempting to connect ', \OCP\Util::DEBUG);
		$cr = $this->ldap->connect($host, $port);
		if(!is_resource($cr)) {
			throw new \Exception($this->l->t('Invalid Host'));
		}

		\OCP\Util::writeLog('user_ldap', 'Wiz: Setting LDAP Options ', \OCP\Util::DEBUG);
		//set LDAP options
		$this->ldap->setOption($cr, LDAP_OPT_PROTOCOL_VERSION, 3);
		$this->ldap->setOption($cr, LDAP_OPT_REFERRALS, 0);
		$this->ldap->setOption($cr, LDAP_OPT_NETWORK_TIMEOUT, self::LDAP_NW_TIMEOUT);
		if($tls) {
			$isTlsWorking = @$this->ldap->startTls($cr);
			if(!$isTlsWorking) {
				return false;
			}
		}

		\OCP\Util::writeLog('user_ldap', 'Wiz: Attemping to Bind ', \OCP\Util::DEBUG);
		//interesting part: do the bind!
		$login = $this->ldap->bind($cr,
									$this->configuration->ldapAgentName,
									$this->configuration->ldapAgentPassword);

		if($login === true) {
			$this->ldap->unbind($cr);
			if($ncc) {
				throw new \Exception('Certificate cannot be validated.');
			}
			\OCP\Util::writeLog('user_ldap', 'Wiz: Bind successful to Port '. $port . ' TLS ' . intval($tls), \OCP\Util::DEBUG);
			return true;
		}

		$errNo = $this->ldap->errno($cr);
		$error = ldap_error($cr);
		$this->ldap->unbind($cr);
		if($errNo === -1 || ($errNo === 2 && $ncc)) {
			//host, port or TLS wrong
			return false;
		} else if ($errNo === 2) {
			return $this->connectAndBind($port, $tls, true);
		}
		throw new \Exception($error);
	}

	/**
	 * checks whether a valid combination of agent and password has been
	 * provided (either two values or nothing for anonymous connect)
	 * @return bool, true if everything is fine, false otherwise
	 */
	private function checkAgentRequirements() {
		$agent = $this->configuration->ldapAgentName;
		$pwd = $this->configuration->ldapAgentPassword;

		return ( (!empty($agent) && !empty($pwd))
		       || (empty($agent) &&  empty($pwd)));
	}

	/**
	 * @param array $reqs
	 * @return bool
	 */
	private function checkRequirements($reqs) {
		$this->checkAgentRequirements();
		foreach($reqs as $option) {
			$value = $this->configuration->$option;
			if(empty($value)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * does a cumulativeSearch on LDAP to get different values of a
	 * specified attribute
	 * @param string[] $filters array, the filters that shall be used in the search
	 * @param string $attr the attribute of which a list of values shall be returned
	 * @param int $dnReadLimit the amount of how many DNs should be analyzed.
	 * The lower, the faster
	 * @param string $maxF string. if not null, this variable will have the filter that
	 * yields most result entries
	 * @return array|false an array with the values on success, false otherwise
	 */
	public function cumulativeSearchOnAttribute($filters, $attr, $dnReadLimit = 3, &$maxF = null) {
		$dnRead = array();
		$foundItems = array();
		$maxEntries = 0;
		if(!is_array($this->configuration->ldapBase)
		   || !isset($this->configuration->ldapBase[0])) {
			return false;
		}
		$base = $this->configuration->ldapBase[0];
		$cr = $this->getConnection();
		if(!$this->ldap->isResource($cr)) {
			return false;
		}
		$lastFilter = null;
		if(isset($filters[count($filters)-1])) {
			$lastFilter = $filters[count($filters)-1];
		}
		foreach($filters as $filter) {
			if($lastFilter === $filter && count($foundItems) > 0) {
				//skip when the filter is a wildcard and results were found
				continue;
			}
			$rr = $this->ldap->search($cr, $base, $filter, array($attr));
			if(!$this->ldap->isResource($rr)) {
				continue;
			}
			$entries = $this->ldap->countEntries($cr, $rr);
			$getEntryFunc = 'firstEntry';
			if(($entries !== false) && ($entries > 0)) {
				if(!is_null($maxF) && $entries > $maxEntries) {
					$maxEntries = $entries;
					$maxF = $filter;
				}
				$dnReadCount = 0;
				do {
					$entry = $this->ldap->$getEntryFunc($cr, $rr);
					$getEntryFunc = 'nextEntry';
					if(!$this->ldap->isResource($entry)) {
						continue 2;
					}
					$rr = $entry; //will be expected by nextEntry next round
					$attributes = $this->ldap->getAttributes($cr, $entry);
					$dn = $this->ldap->getDN($cr, $entry);
					if($dn === false || in_array($dn, $dnRead)) {
						continue;
					}
					$newItems = array();
					$state = $this->getAttributeValuesFromEntry($attributes,
																$attr,
																$newItems);
					$dnReadCount++;
					$foundItems = array_merge($foundItems, $newItems);
					$this->resultCache[$dn][$attr] = $newItems;
					$dnRead[] = $dn;
				} while(($state === self::LRESULT_PROCESSED_SKIP
						|| $this->ldap->isResource($entry))
						&& ($dnReadLimit === 0 || $dnReadCount < $dnReadLimit));
			}
		}

		return array_unique($foundItems);
	}

	/**
	 * determines if and which $attr are available on the LDAP server
	 * @param string[] $objectclasses the objectclasses to use as search filter
	 * @param string $attr the attribute to look for
	 * @param string $dbkey the dbkey of the setting the feature is connected to
	 * @param string $confkey the confkey counterpart for the $dbkey as used in the
	 * Configuration class
	 * @param bool $po whether the objectClass with most result entries
	 * shall be pre-selected via the result
	 * @return array, list of found items.
	 * @throws \Exception
	 */
	private function determineFeature($objectclasses, $attr, $dbkey, $confkey, $po = false) {
		$cr = $this->getConnection();
		if(!$cr) {
			throw new \Exception('Could not connect to LDAP');
		}
		$p = 'objectclass=';
		foreach($objectclasses as $key => $value) {
			$objectclasses[$key] = $p.$value;
		}
		$maxEntryObjC = '';

		//how deep to dig?
		//When looking for objectclasses, testing few entries is sufficient,
		$dig = 3;

		$availableFeatures =
			$this->cumulativeSearchOnAttribute($objectclasses, $attr,
											   $dig, $maxEntryObjC);
		if(is_array($availableFeatures)
		   && count($availableFeatures) > 0) {
			natcasesort($availableFeatures);
			//natcasesort keeps indices, but we must get rid of them for proper
			//sorting in the web UI. Therefore: array_values
			$this->result->addOptions($dbkey, array_values($availableFeatures));
		} else {
			throw new \Exception(self::$l->t('Could not find the desired feature'));
		}

		$setFeatures = $this->configuration->$confkey;
		if(is_array($setFeatures) && !empty($setFeatures)) {
			//something is already configured? pre-select it.
			$this->result->addChange($dbkey, $setFeatures);
		} else if($po && !empty($maxEntryObjC)) {
			//pre-select objectclass with most result entries
			$maxEntryObjC = str_replace($p, '', $maxEntryObjC);
			$this->applyFind($dbkey, $maxEntryObjC);
			$this->result->addChange($dbkey, $maxEntryObjC);
		}

		return $availableFeatures;
	}

	/**
	 * appends a list of values fr
	 * @param resource $result the return value from ldap_get_attributes
	 * @param string $attribute the attribute values to look for
	 * @param array &$known new values will be appended here
	 * @return int, state on of the class constants LRESULT_PROCESSED_OK,
	 * LRESULT_PROCESSED_INVALID or LRESULT_PROCESSED_SKIP
	 */
	private function getAttributeValuesFromEntry($result, $attribute, &$known) {
		if(!is_array($result)
		   || !isset($result['count'])
		   || !$result['count'] > 0) {
			return self::LRESULT_PROCESSED_INVALID;
		}

		// strtolower on all keys for proper comparison
		$result = \OCP\Util::mb_array_change_key_case($result);
		$attribute = strtolower($attribute);
		if(isset($result[$attribute])) {
			foreach($result[$attribute] as $key => $val) {
				if($key === 'count') {
					continue;
				}
				if(!in_array($val, $known)) {
					$known[] = $val;
				}
			}
			return self::LRESULT_PROCESSED_OK;
		} else {
			return self::LRESULT_PROCESSED_SKIP;
		}
	}

	/**
	 * @return bool|mixed
	 */
	private function getConnection() {
		if(!is_null($this->cr)) {
			return $this->cr;
		}
		$cr = $this->ldap->connect(
			$this->configuration->ldapHost.':'.$this->configuration->ldapPort,
			$this->configuration->ldapPort);

		$this->ldap->setOption($cr, LDAP_OPT_PROTOCOL_VERSION, 3);
		$this->ldap->setOption($cr, LDAP_OPT_REFERRALS, 0);
		$this->ldap->setOption($cr, LDAP_OPT_NETWORK_TIMEOUT, self::LDAP_NW_TIMEOUT);
		if($this->configuration->ldapTLS === 1) {
			$this->ldap->startTls($cr);
		}

		$lo = @$this->ldap->bind($cr,
								 $this->configuration->ldapAgentName,
								 $this->configuration->ldapAgentPassword);
		if($lo === true) {
			$this->$cr = $cr;
			return $cr;
		}

		return false;
	}

	/**
	 * @return array
	 */
	private function getDefaultLdapPortSettings() {
		static $settings = array(
								array('port' => 7636, 'tls' => false),
								array('port' =>  636, 'tls' => false),
								array('port' => 7389, 'tls' => true),
								array('port' =>  389, 'tls' => true),
								array('port' => 7389, 'tls' => false),
								array('port' =>  389, 'tls' => false),
						  );
		return $settings;
	}

	/**
	 * @return array
	 */
	private function getPortSettingsToTry() {
		//389 ← LDAP / Unencrypted or StartTLS
		//636 ← LDAPS / SSL
		//7xxx ← UCS. need to be checked first, because both ports may be open
		$host = $this->configuration->ldapHost;
		$port = intval($this->configuration->ldapPort);
		$portSettings = array();

		//In case the port is already provided, we will check this first
		if($port > 0) {
			$hostInfo = parse_url($host);
			if(!(is_array($hostInfo)
				&& isset($hostInfo['scheme'])
				&& stripos($hostInfo['scheme'], 'ldaps') !== false)) {
				$portSettings[] = array('port' => $port, 'tls' => true);
			}
			$portSettings[] =array('port' => $port, 'tls' => false);
		}

		//default ports
		$portSettings = array_merge($portSettings,
		                            $this->getDefaultLdapPortSettings());

		return $portSettings;
	}


}

<?php
/**
 * ownCloud - user_saml
 *
 * @author Sixto Martin <smartin@yaco.es>
 * @copyright 2012 Yaco Sistemas // CONFIA
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

/**
 * This class contains all hooks.
 */
class OC_USER_SAML_Hooks {

	static public function post_login($parameters) {
		$userid = $parameters['uid'];
		$samlBackend = new OC_USER_SAML();

		if ($samlBackend->auth->isAuthenticated()) {
			$attributes = $samlBackend->auth->getAttributes();

			$usernameFound = false;
			foreach($samlBackend->usernameMapping as $usernameMapping) {
				if (array_key_exists($usernameMapping, $attributes) && !empty($attributes[$usernameMapping][0])) {
					$usernameFound = true;
					$uid = $attributes[$usernameMapping][0];
					OC_Log::write('saml','Authenticated user '.$uid,OC_Log::DEBUG);
					break;
				}
			}

			if ($usernameFound && $uid == $userid) {
				if (OC_User::userExists($uid)) {
					if ($samlBackend->updateUserData) {
						$attrs = get_user_attributes($uid, $samlBackend);
						update_user_data($uid, $attrs['email'], $attrs['groups'],
							$attrs['protected_groups'], $attrs['display_name']);
					}
				}
				return true;
			}
		}
		return false;
	}

	static public function post_createUser($parameters) {
		$uid = $parameters['uid'];
		$samlBackend = new OC_USER_SAML();
		$attrs = get_user_attributes($uid, $samlBackend);
		if (!$samlBackend->updateUserData) {
			// Ensure that user data will be filled atleast once
			update_user_data($uid, $attrs['email'], $attrs['groups'],
				$attrs['protected_groups'], $attrs['display_name'], true);
		}
		$message = "An ownCloud account at Data Storage CESNET (%s) has been succesfully created for you.\r\n\r\n"
			. "Details about your account:\r\nUsername: %s\r\nData quota: %s\r\n\r\nIf you wish to use "
			. "synchronization client apps, please set your password here:\r\n%s\r\n\r\nYour account is"
			. " bound to Identity Provider used at first login. If you have identities at multiple IdP's,"
			. " always use your identity used at first login (%s) in order to access your data.\r\n\r\nIf"
			. " you have questions or problems, feel free to contact us at du-support@cesnet.cz.";
		$domain = \OCP\Util::linkToAbsolute('index.php','');
		$quota = OCP\Util::humanFileSize(OC_Util::getUserQuota($uid));
		$psettings = \OCP\Util::linkToAbsolute('index.php', 'settings/personal');
		$manpage1 = 'https://du.cesnet.cz/wiki/doku.php/cs/navody/owncloud/';
		$manpage2 = \OC_Helper::linkToDocs('user-manual');
		send_email('account creation', $message, 
			array($domain, $uid, $quota, $psettings, $uid, $manpage1, $manpage2), $attrs['email']);
	}

	static public function post_setPassword($uid, $password, $recoveryPassword) {
		$message = "You are receiving this e-mail because your ownCloud password for client apps has changed."
			. "\r\n\r\nIf you didn't change your password, please contact du-support@cesnet.cz";
		send_email('password change', $message);
	}

	static public function logout($parameters) {
		$samlBackend = new OC_USER_SAML();
		if ($samlBackend->auth->isAuthenticated()) {
			OC_Log::write('saml', 'Executing SAML logout', OC_Log::DEBUG);
			unset($_COOKIE["SimpleSAMLAuthToken"]);
			setcookie('SimpleSAMLAuthToken', '', time()-3600, \OC::$WEBROOT);
			setcookie('SimpleSAMLAuthToken', '', time()-3600, \OC::$WEBROOT . '/');
			$samlBackend->auth->logout();
		}
		return true;
	}
}

function get_user_attributes($uid, $samlBackend) {
	$attributes = $samlBackend->auth->getAttributes();
	$result['email'] = '';
	foreach ($samlBackend->mailMapping as $mailMapping) {
		if (array_key_exists($mailMapping, $attributes) && !empty($attributes[$mailMapping][0])) {
			$result['email'] = $attributes[$mailMapping][0];
			break;
		}
	}

	$result['display_name'] = '';
	foreach ($samlBackend->displayNameMapping as $displayNameMapping) {
		if (array_key_exists($displayNameMapping, $attributes) && !empty($attributes[$displayNameMapping][0])) {
			$result['display_name'] = $attributes[$displayNameMapping][0];
			break;
		}
	}

	$result['groups'] = array();
	foreach ($samlBackend->groupMapping as $groupMapping) {
		if (array_key_exists($groupMapping, $attributes) && !empty($attributes[$groupMapping])) {
			$result['groups'] = array_merge($result['groups'], $attributes[$groupMapping]);
		}
	}
	if (empty($result['groups']) && !empty($samlBackend->defaultGroup)) {
		$result['groups'] = array($samlBackend->defaultGroup);
		OCP\Util::writeLog('saml','Using default group "'.$samlBackend->defaultGroup.'" for the user: '.$uid, OCP\Util::DEBUG);
	}
	$result['protected_groups'] = $samlBackend->protectedGroups;
	return $result;	
}


function update_user_data($uid, $email=null, $groups=null, $protectedGroups='', $displayName=null, $just_created=false) {
	OC_Util::setupFS($uid);
	OCP\Util::writeLog('saml','Updating data of the user: '.$uid, OCP\Util::DEBUG);
	if(isset($email)) {
		update_mail($uid, $email);
	}
	if (isset($groups)) {
		update_groups($uid, $groups, $protectedGroups, $just_created);
	}
	if (isset($displayName)) {
		update_display_name($uid, $displayName);
	}
}	


function update_mail($uid, $email) {
	if ($email != OC_Preferences::getValue($uid, 'settings', 'email', '')) {
		OC_Preferences::setValue($uid, 'settings', 'email', $email);
		OC_Log::write('saml','Set email "'.$email.'" for the user: '.$uid, OC_Log::DEBUG);
	}
}


function update_groups($uid, $groups, $protectedGroups=array(), $just_created=false) {

	if(!$just_created) {
		$old_groups = OC_Group::getUserGroups($uid);
		foreach($old_groups as $group) {
			if(!in_array($group, $protectedGroups) && !in_array($group, $groups)) {
				OC_Group::removeFromGroup($uid,$group);
				OC_Log::write('saml','Removed "'.$uid.'" from the group "'.$group.'"', OC_Log::DEBUG);
			}
		}
	}

	foreach($groups as $group) {
		if (preg_match( '/[^a-zA-Z0-9 _\.@\-]/', $group)) {
			OC_Log::write('saml','Invalid group "'.$group.'", allowed chars "a-zA-Z0-9" and "_.@-" ',OC_Log::DEBUG);
		}
		else {
			if (!OC_Group::inGroup($uid, $group)) {
				if (!OC_Group::groupExists($group)) {
					OC_Group::createGroup($group);
					OC_Log::write('saml','New group created: '.$group, OC_Log::DEBUG);
				}
				OC_Group::addToGroup($uid, $group);
				OC_Log::write('saml','Added "'.$uid.'" to the group "'.$group.'"', OC_Log::DEBUG);
			}
		}
	}
}


function update_display_name($uid, $displayName) {
	OC_User::setDisplayName($uid, $displayName);
}


function send_email($subject, $message, $args=array(), $user=null) {
	$from = \OCP\Util::getDefaultEmailAddress('noreply');
	if (!$user) {
		$user = \OCP\Config::getUserValue(OCP\User::getUser(), 'settings', 'email', '');
	}
	\OCP\Util::writeLog('saml','Sending email to '. $user, OCP\Util::DEBUG);
	$l = \OCP\Util::getL10N('user_saml');
	try {
		$defaults = new \OCP\Defaults();
		\OCP\Util::sendMail($user, '', $l->t('%s '.$subject, array($defaults->getName())), $l->t($message, $args), $from, $defaults->getName());
	} catch (Exception $e) {
		\OCP\Util::writeLog('saml','Sending email to '. $user .' failed.', OC_Log::ERROR);
	}
}

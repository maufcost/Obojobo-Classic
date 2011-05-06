<?php
namespace rocketD\auth;
class ModInternal extends AuthModule {
	
	protected static $instance;
	
	
	const OPT_ENFORCE_RESET = true;	
	// TODO: put password change time into the authmod
	const MAX_USERNAME_LENGTH = '255';
	const MIN_USERNAME_LENGTH = '2';	
	
	const CAN_CHANGE_PW = true; // override this!
	
	const PW_CHANGE_TIME = 'lastPassChange';
	const RESET_KEY = 'resetKey';
	const RESET_TIME = 'resetTime';
	
	static public function getInstance()
	{
		if(!isset(self::$instance))
		{
			$selfClass = __CLASS__;
			self::$instance = new $selfClass();
		}
		return self::$instance;
	}
	// security check: Ian Turgeon 2008-05-06 - PASS
	public function fetchUserByID($userID = 0)
	{
		return parent::fetchUserByID($userID);
	}
	// security check: Ian Turgeon 2008-05-07 - FAIL (need to make sure this is an administrator/system only function, client should never have a list of all users)
	public function getAllUsers()
	{
		return parent::getAllUsers();
	}
	
	// security check: Ian Turgeon 2008-05-08 - PASS
	public function recordExistsForID($userID=0)
	{
		return parent::recordExistsForID($userID);
	}
	
	// security check: Ian Turgeon 2008-05-08 - PASS	
	public function createNewUser($userName, $fName, $lName, $mName, $email, $optionalVars=0)
	{
		$valid = $this->checkRegisterPossible($userName, $fName, $lName, $mName, $email, $optionalVars);
		if($valid === true)
		{
			$this->defaultDBM();
			if(!$this->DBM->connected)
			{
				trace('not connected', true);
				return false;
			}
			$this->DBM->startTransaction();
			$result = parent::createNewUser($fName, $lName, $mName, $email, $optionalVars);
			if($result['success'] == true)
			{
				trace('core user created');
				// password required before this, no need to check
				if(!$this->addRecord($result['userID'], $userName, $optionalVars['MD5Pass']))
				{
					trace('Unable to add record.', true);
					$this->DBM->rollBack();
					return array('success' => false, 'error' => 'Unable to create user.');
				}
				$this->DBM->commit();
				return array('success' => true, 'userID' => $result['userID']);
			}
			else
			{
				$this->DBM->rollBack();
				trace(print_r($result, true), true);
				return $result;
			}
		}
		else{
			trace(print_r($valid, true), true);
			return array('success' => false, 'error' => $valid);
		}		
	}

	// security check: Ian Turgeon 2008-05-08 - PASS	
	public function checkRegisterPossible($userName, $fName, $lName, $mName, $email, $optionalVars=0){
		// validate username
		$validUsername = $this->validateUsername($userName);
		if($validUsername !== true)
		{
			trace($validUsername, true);
			return $validUsername;
		}
		if(!$this->validateFirstName($fName))
		{
			trace('Invalid first name', true);
			return 'Invalid first name';
		}
		if(!$this->validateLastName($lName))
		{
			trace('Invalid last name', true);
			return 'Invalid last name';
		}
		if(!$this->validateEmail($email))
		{
			trace('Invalid email address', true);
			return 'Invalid email address';
		}
		// registration requires a password
		$vPass = $this->validatePassword($optionalVars['MD5Pass']);
		if($vPass !== true)
		{
			return $vPass;
		}
		// make sure the username is not already used
		if($this->getUIDforUsername($userName) !== false)
		{
			trace('UserName not available.', true);
			return 'UserName not available.';
		}		
		return true;
	}
	
	// security check: Ian Turgeon 2008-05-06 - PASS
	public function getUIDforUsername($username)
	{
		return parent::getUIDforUsername($username);
	}
	
	// security check: Ian Turgeon 2008-05-08 - PASS		
	public function updateUser($userID, $userName, $fName, $lName, $mName, $email, $optionalVars=0)
	{
		// validate arguments
		if(!$this->validateUID($userID)) return array('success' => false, 'error' => 'Invalid User Id.');
		
 		$this->defaultDBM();
		if(!$this->DBM->connected)
		{
			trace('not connected', true);
			return false;
		}	
		$user = $this->fetchUserByID($userID);
		if($user != false)
		{
			$this->DBM->startTransaction();
			$result = parent::updateUser($userID, $fName, $lName, $mName, $email, $optionalVars);
			if($result['success']==true)
			{
				if($this->updateRecord($userID, $userName, $optionalVars['MD5Pass']))
				{
					return array('success' => true);
				}		
			}
			$this->DBM->rollBack();
			trace('Unable to update user.', true);
			return array('success' => false, 'error' => 'Unable to update user.');			
		}
		trace('Unable to locate user.', true);
		return array('success' => false, 'error' => 'Unable to locate user.');
	}
		
	/**
	 * Authenticates the user.  This module uses the internal database to verify a user.  If the user doesnt exist, none will be created.
	 * Parent doc: Main Authentication function. This function will verify the user's crudentials and log them in. Must be extended to return a \rocketD\auth\User upon success, and false on failure.
	 *
	 * @return true/false
	 * @author Ian Turgeon
	 **/	
	// security check: Ian Turgeon 2008-05-06 - PASS	
	public function authenticate($requestVars)
	{
		// security first, check request vars for valid data
		// check for required vars
		// require userName
		if($this->validateUsername($requestVars['userName']) !== true)
		{
			return false;	
		} 
		// requre password
		if($this->validatePassword($requestVars['password']) !== true)
		{
			return false;
		}
		// begin authentication, lookup user id by username
		if($userID = $this->getUIDforUsername($requestVars['userName']))
		{
			// fetch the user
			if($tmpUser = $this->fetchUserByID($userID))
			{	
				// verify the password
				if($this->verifyPassword($tmpUser, $requestVars['password']))
				{
					// login
					$this->storeLogin($tmpUser->userID);
					$this->internalUser = $tmpUser;
					return true;
				}
				else
				{
					trace('incorrect password');
				}
			}
			else
			{
				trace('unable to fetch user', true);
			}
		}
		else
		{
			trace('unable to fetch username');
		}
		return false;
	}
	
	/**
	 * Verify the supplied password.  The default methodology takes in an md5 password as an argument, concatinates that with an md5 salt from the database, and md5's the result.
	 *
	 * @return true/false
	 * @author Ian Turgeon
	 **/
	// security check: Ian Turgeon 2008-05-06 - PASS
	public function verifyPassword($user, $password)
	{
		$user->verified = false; // reset user verified flag
		// validate input
		// if password isnt md5, md5 it
		if( !\obo\util\Validator::isMD5($password) )
		{
			$password = md5($password);
		}
		
		if($this->validatePassword($password) !== true) return false;
		if(!$this->validateUID($user->userID))
		{
			trace('invalid User ID', true);
			return false;
		} 
		// establish db connection
		$this->defaultDBM();
		if(!$this->DBM->connected)
		{
			trace('not connected', true);
			return false;
		}
		
		// check the db for a correct salt/pw hash
		
		$q = $this->DBM->querySafe("SELECT * FROM (SELECT `value` FROM obo_user_meta WHERE userID = '?' AND meta = 'password') AS B WHERE `value` = MD5(CONCAT( (SELECT `value` FROM obo_user_meta WHERE userID = '?' AND meta = 'salt'), '?'))", $user->userID, $user->userID, $password);
		if($r = $this->DBM->fetch_obj($q))
		{
			//ok session id was successfull, so store the stuff we need and return true
			$user->verified = true;
		}
		
		return $user->verified;
	}



	// security check: Ian Turgeon 2008-05-06 - PASS	
	protected function createSalt()
	{
		return md5(uniqid(rand(), true));
	}

	// security check: Ian Turgeon 2008-05-08 - PASS
	protected function addRecord($userID, $userName, $password)
	{
		if(!$this->validateUID($userID)) return false;
		if($this->validateUsername($userName) !== true) return false;
		if($this->validatePassword($password) !== true) return false;	
		$this->defaultDBM();
		if(!$this->DBM->connected)
		{
			trace('not connected', true);
			return false;
		}		
		$salt = $this->createSalt();
		
		
		$qstr = "INSERT INTO obo_user_meta SET userID = '?', meta='?', value = '?'";
		$result = $this->DBM->querySafe($qstr, $userID, 'password', $password); // add password
		$result = $result &&  $this->DBM->querySafe($qstr, $userID, 'salt', $salt); // add salt
		// set the login and auth_module
		$result = $result &&  $this->DBM->querySafe("UPDATE ".\cfg_core_User::TABLE." SET ".\cfg_core_User::LOGIN." = '?', ".\cfg_core_User::AUTH_MODULE." = '?' WHERE ".\cfg_core_User::ID." = '?' ", $userName, get_class($this), $userID);

		return $result;
	}
	


	// security check: Ian Turgeon 2008-05-07 - PASS	
	public function updateRecord($userID, $userName, $password)
	{
		if(!$this->validateUID($userID)) return false;
		
		$this->defaultDBM();
		if(!$this->DBM->connected)
		{
			trace('not connected', true);
			return false;
		}
		
		$successCheck1 = true;
		// update username
		if($this->validateUsername($userName) === true)
		{
			$successCheck1 = $this->DBM->querySafe("UPDATE ".\cfg_core_User::TABLE." set ".\cfg_core_User::LOGIN."='?' WHERE ".\cfg_core_User::ID."='?' LIMIT 1", $userName, $userID);
			// remove any cache references that use this username
			
			\rocketD\util\Cache::getInstance()->clearUserByID($userID);
		}
		$successCheck2 = true;
		// update password		
		if($this->validatePassword($password) === true)
		{
			$salt = $this->createSalt();
			$successCheck2 =  $this->DBM->querySafe("UPDATE obo_user_meta set 'value' = MD5(CONCAT('?', '?')) WHERE ".\cfg_core_User::ID."='?' AND meta = 'salt' LIMIT 1", $salt, $password, $userID);
			$successCheck2 =  $successCheck2 && $this->DBM->querySafe("UPDATE obo_user_meta set 'value' = '?' WHERE ".\cfg_core_User::ID."='?' AND meta = 'password' LIMIT 1", $password, $userID);
			$this->DBM->querySafe("UPDATE ".\cfg_core_User::TABLE." set ".self::PW_CHANGE_TIME."='".time()."' WHERE ".\cfg_core_User::ID."='?'", $userID);
			//  no need to update cache, password doesn't use cache
		}
		return $successCheck1 && $successCheck2;
	}

	// security check: Ian Turgeon 2008-05-06 - PASS
	public function validateUsername($username)
	{
		// check for any characters that arent alphanumeric or the tilde '~'

		// make sure it starts with a tilde
		//if(substr($username, 0, 1) != '~'){
		//	return 'User name must start with a ~.';
		//}
		// make sure the string length is less then 255, our usernames aren't that long
		if(strlen($username) > self::MAX_USERNAME_LENGTH)
		{
			return 'User name maximum length is 255 characters.';
		}
		// make sure the username is atleast 2 characters
		if(strlen($username) < self::MIN_USERNAME_LENGTH)
		{
			return 'User name minimum length is 2 characters.';
		}
		if(preg_match("/^~{1}[[:alnum:]]{".self::MIN_USERNAME_LENGTH.",".self::MAX_USERNAME_LENGTH."}$/i", $username) == false)
		{
			return 'User name can only contain alpha numeric characters (in addition to the tilda).';
		}	
		return true;
	}

	// security check: Ian Turgeon 2008-05-06 - PASS
	public function validatePassword($pass)
	{
		// password is an md5 hash of an empty string
		if($pass == 'd41d8cd98f00b204e9800998ecf8427e')
		{
			return 'Password is an empty string';
		}
		return true;
	}
	
	// security check: Ian Turgeon 2008-05-07 - PASS
	public function removeRecord($userID)
	{
		if(!$this->validateUID($userID)) return false;
		$return = parent::removeRecord($userID); // remove user
		$this->defaultDBM();
		if(!$this->DBM->connected)
		{
			trace('not connected', true);
			return false;
		}
		trace('deleting record '. $userID, true);
		// remove authentication module record
		return $return && $this->DBM->querySafe("DELETE FROM obo_user_meta WHERE userID = '?'", $userID);
	}
	
	public function dbSetPassword($userID, $newPassword)
	{
		if(!$this->validateUID($userID)) return false;
		
		$this->defaultDBM();

		// if password isnt md5, md5 it
		if( !\obo\util\Validator::isMD5($newPassword) )
		{
			$newPassword = md5($newPassword);
		}
		// update password		
		if($this->validatePassword($newPassword) === true)
		{
			$salt = $this->createSalt();
			// update password
			$qstr = "UPDATE obo_user_meta SET value = '?' WHERE userID = '?' and meta = '?'";
			$a = (bool) $this->DBM->querySafe($qstr, $salt, $userID, 'salt');
			$b = (bool) $this->DBM->querySafe($qstr, md5($salt.$password), $userID, 'password');
			$c = (bool) $this->DBM->querySafe($qstr, time(), $userID, 'lastPassChange');
			return $a && $b && $c;
		}
		return false;
	}
	
	public function isPasswordCurrent($userID)
	{
		if($this->validateUID($userID))
		{
			
			$this->defaultDBM();
			if($q = $this->DBM->querySafe("SELECT value FROM obo_user_meta WHERE ".\cfg_core_User::ID."='?' AND meta='lastPassChange'", $userID))
			{
				if($r = $this->DBM->fetch_obj($q))
				{
					
					return ((int)$r->value +  \AppCfg::AUTH_PW_LIFE) > time();
				}
			}
			return true;
		}
		return false;

	}
	public function requestPasswordReset($username, $email, $returnURL)
	{
		
		
		// validate required arguments
		if($this->validateUsername($username) !== true)
		{
			
			return \rocketD\util\Error::getError(2);
		}
		if(!$this->validateEmail($email))
		{
			trace('email invalid', true);
			
			return \rocketD\util\Error::getError(2);
		}
		if(!$this->validateResetURL($returnURL))
		{
			trace('request URL invalid', true);
			
			return \rocketD\util\Error::getError(2);
		}
		// try to ge the user
		if(!($userID = $this->getUIDforUsername($username)))
		{
			trace('getUID for username failed', true);
			
			return \rocketD\util\Error::getError(2);
		}
		if(!($user = $this->fetchUserByID($userID)))
		{
			trace('couldnt fetch user by id', true);
			
			return \rocketD\util\Error::getError(1000);
		}
		// validate email address
		if(strtolower($user->{\cfg_core_User::EMAIL}) != strtolower(trim($email)))
		{
			trace('incorrect email '.$email, true);
			
			return \rocketD\util\Error::getError(2);
		}
		
		trace('reset request working', true);
		$this->defaultDBM();
		// first check to see if there is an existing valid reset key
		$q = $this->DBM->querySafe("SELECT (SELECT value FROM `obo_user_meta` WHERE `".\cfg_core_User::ID."` = '?' AND meta = '".self::RESET_KEY."') AS ".self::RESET_KEY.",  (SELECT value FROM `obo_user_meta` WHERE `".\cfg_core_User::ID."` = '?' AND meta = '".self::RESET_TIME."') AS ".self::RESET_TIME, $user->userID, $user->userID);
		if($r = $this->DBM->fetch_obj($q))
		{
			// no existing key, or invalid one, make new
			if(strlen($r->{self::RESET_KEY}) == 0 || $r->{self::RESET_TIME} + \AppCfg::AUTH_PW_LIFE < time())
			{
				$this->DBM->startTransaction();
				$resetKey = $this->makeResetKey();
				
				$qstr = "INSERT INTO obo_user_meta SET userID = '?', meta ='?', value='?' ON DUPLICATE KEY UPDATE value = '?'";
				$resetQ = $this->DBM->querySafe($qstr, $user->userID, self::RESET_KEY,  $resetKey,  $resetKey);
				$timeQ = $this->DBM->querySafe($qstr, $user->userID, self::RESET_TIME,  time(),  time());
				if($timeQ && $resetQ)
				{
					// send email
					$emailSent = $this->sendPasswordResetEmail($user->{\cfg_core_User::EMAIL}, $returnURL, $resetKey);
					if($emailSent)
					{
						$this->DBM->commit();
						return true;
					}
					else
					{
						
						return \rocketD\util\Error::getError(1005);
					}
				}
				$this->DBM->rollBack();
			}
			// existing key still valid, send email with existing key
			else
			{
				// send email
				$emailSent = $this->sendPasswordResetEmail($user->{\cfg_core_User::EMAIL}, $returnURL, $r->{self::RESET_KEY});
				if($emailSent)
				{
					$this->DBM->commit();
					return true;
				}
				else
				{
					
					return \rocketD\util\Error::getError(1005);
				}
			}
		}
		trace('couldnt find previous reset keys', true);
		
		return \rocketD\util\Error::getError(2);
	}

	protected function sendPasswordResetEmail($sendTo, $returnURL, $resetKey)
	{
		
		trace("mailing password reset " . $resetKey . ' ' . $sendTo, true);
		$headers = 'From: '.\AppCfg::SYS_EMAIL . "\r\n" .
		    'Reply-To: '. \AppCfg::SYS_EMAIL . "\r\n" .
		    'X-Mailer: PHP/' . phpversion();
		$title = \AppCfg::SYS_NAME." Password Request";
		// if the url doesn't contain a ?, it'll need one
		if(strripos($returnURL, '?') == false)
		{
			$returnURL .= '?';
		}
		else // it has a ?, add an & for the reset key
		{
			$returnURL .= '&';
		}
		$body = "You either started a new account or requested a password reset for your existing ".\AppCfg::SYS_NAME." account.\r\n\r\nFollow this link to create your password: ".$returnURL."resetKey=".$resetKey;
		$success = false;
		// try to send email 10 times before failing
		for($i = 0; $i < 10; $i++)
		{
			$success = mail($sendTo, $title, $body , $headers);
			if($success)
			{
				break;
			}
			else
			{
				trace('failed sending email '. $i . ': '.$sendTo, true);
				sleep(1);
			}
		}
		return $success;
	}

	public function changePasswordWithKey($username, $key, $newpass)
	{
		// if password isnt md5, md5 it
		if( !\obo\util\Validator::isMD5($newpass) )
		{
			$newpass = md5($newpass);
		}
		if($this->validateUsername($username) === true)
		{
			$userID = $this->getUIDforUsername($username);
			if($userID)
			{
				$this->defaultDBM();
				
				$q = $this->DBM->querySafe("SELECT (SELECT value FROM `obo_user_meta` WHERE `".\cfg_core_User::ID."` = '?' AND meta = '".self::RESET_KEY."') AS ".self::RESET_KEY.",  (SELECT value FROM `obo_user_meta` WHERE `".\cfg_core_User::ID."` = '?' AND meta = '".self::RESET_TIME."') AS ".self::RESET_TIME, $user->userID, $user->userID);
				if($r = $this->DBM->fetch_obj($q))
				{
					trace($key, true);
					trace($r->{self::RESET_KEY}, true);
					
					if($key == $r->{self::RESET_KEY} && ($r->{self::RESET_TIME} + \AppCfg::AUTH_PW_LIFE > time() ))
					{;
						if($this->validatePassword($newpass) === true) // validate new
						{ 
							if($this->dbSetPassword($userID, $newpass))
							{
								$qstr = "DELETE FROM obo_user_meta WHERE ".\cfg_core_User::ID." = '?' AND (meta = '?' OR meta = '?') ";
								$this->DBM->querySafe($qstr, $userID, self::RESET_KEY, self::RESET_TIME);
								return true;
							}
						}
					}
				}
				return \rocketD\util\Error::getError(1006);
			}
		}
		return false;
	}	
}
?>
<?php
class nm_los_LOSystem extends core_db_dbEnabled
{
	
	const DB_CLEAN_TIME='System_DB_LastCleanTime';
	const DB_CLEAN_INDEX='System_DB_LastCleanIndex';

	public function cleanOrphanData($forceExecute=false, $forceStackID=false)
	{
		
		$this->defaultDBM();
		$q = $this->DBM->query("SELECT ".cfg_obo_Temp::VALUE." FROM ".cfg_obo_Temp::TABLE." WHERE ".cfg_obo_Temp::ID."='".self::DB_CLEAN_TIME."'");
		if($r = $this->DBM->fetch_obj($q))
		{
			$timeExists = true;
			// last execution too long ago, execute
			if(time() - AppCfg::DB_CLEAN_INTERVAL > $r->{cfg_obo_Temp::VALUE} && $forceExecute != true)
			{
				$forceExecute = true;
			}				
		}
		// no value set, execute
		else
		{
			$timeExists = false;
			$forceExecute = true;
		}
		
		if($forceExecute)
		{
			$q = $this->DBM->query("SELECT ".cfg_obo_Temp::VALUE." FROM ".cfg_obo_Temp::TABLE." WHERE ".cfg_obo_Temp::ID."='".self::DB_CLEAN_INDEX."'");
			if($r = $this->DBM->fetch_obj($q)){
				$indexExists = true;
				$stackIndex = (int) $r->{cfg_obo_Temp::VALUE};
			}
			// no value set, execute
			else
			{
				$stackIndex = 1;
				$indexExists = false;
			}
			// -1 indicates that this script is currently being called by someone else
			if($stackIndex != -1)
			{
				$q = $this->DBM->query("UPDATE ".cfg_obo_Temp::TABLE." SET ".cfg_obo_Temp::VALUE."='-1' WHERE ".cfg_obo_Temp::ID."='".self::DB_CLEAN_INDEX."'");	
				$total = microtime(true);
				switch($stackIndex)
				{
					case 1:
						// move MASTER lo's with no perms to the deleted table
						$this->cleanLOs();
						
						break;
					case 2:
				   	// move ownerless instances to deleted table
						$this->cleanInstances();
						break;
					CASE 3:
						// Removes pages mapped to a non-existant learning object
						// Removes pages with no mapping 
						$this->cleanContentPages();
						break;
					case 4:
						// Removes all pageitem mappings for pageitems mapped to non-existant pages
						// Removes all pageitems with no mapping
//						$this->cleanContentPageItems();
						break;
					case 5:					
						// Finds and removes descriptions with no references from learning objects
//						$this->cleanDescriptions();
						break;
					case 6:				
						// Removes Learning Object Groups (Agroups and Pgroups) with no LO references
						// This will not delete qgroups for deleted Masters, just drafts
						// Removes all qgroup mappings to missing qgroups
						$this->cleanQGroups();
						break;
					case 7:	
						// Removes all question scores associated with a missing qgroup
						$this->cleanScores();
						break;
					case 8:	
						// Removes all author mapping for missing learning objects
						$this->cleanAuthors();
						break;
					case 9:
						// Removes all permissions mapped to a missing learning object		
						// Removes all perms to instances with no lerning object		
						$this->cleanPerms();
						break;
					case 10:		
						// Removes all questions with no qgroup
						// Removes all media with no qgroup
						// Removes all QA mappings with no question	
						// Removes all media mapping with no media items	
						// Removes all answers not mapped to a question			
						//$this->cleanQuestions();
						break;
					case 11:	
						// Removes all keyword mappings with no lo
						// Removes all keywords with no mapping
						$this->cleanKeywords();
						break;
						// Removes all instance based tracking calls with no associated instances		
						$this->cleanTracking();
					case 12:
						// Removes all visits with no instances
						// removes all attempts with no visit		
						$this->cleanVisits();
						break;
					case 13:	
						// removes all locks with no learning object	
						$this->cleanLocks();
						break;
					case 14:	
						// removes all expired cache.
						$this->purgeLOCache();
					default:
						$stackIndex = 0;
						break;
				}
				$stackIndex++;
				$TM = nm_los_TrackingManager::getInstance();
				$TM->trackCleanOrphans((microtime(true) - $total));
			
				// log the time so we don't do this too often
				if(!$timeExists)
				{
					$q = $this->DBM->query("INSERT INTO ".cfg_obo_Temp::TABLE." SET ".cfg_obo_Temp::ID."='".self::DB_CLEAN_TIME."', ".cfg_obo_Temp::VALUE."='". time() ."'");
				}
				else
				{
					$q = $this->DBM->query("UPDATE ".cfg_obo_Temp::TABLE." SET ".cfg_obo_Temp::ID."='". time() ."' WHERE ".cfg_obo_Temp::VALUE."='".self::DB_CLEAN_TIME."' ");
				}
			
				// store index
				if(!$indexExists)
				{
					$q = $this->DBM->query("INSERT INTO ".cfg_obo_Temp::TABLE." SET ".cfg_obo_Temp::ID."='".self::DB_CLEAN_INDEX."', ".cfg_obo_Temp::VALUE."='". $stackIndex ."'");
				}
				else
				{
					$q = $this->DBM->query("UPDATE ".cfg_obo_Temp::TABLE." SET ".cfg_obo_Temp::VALUE."='". $stackIndex ."' WHERE ".cfg_obo_Temp::ID."='".self::DB_CLEAN_INDEX."' ");
				}			
			}
		}
	}

	public function cleanLOs()
	{
		// move MASTER lo's with no perms to the deleted table
		$t = microtime(true);
		$moveCount = 0;
		// TODO: abstract perms
		$qstr = "SELECT ".cfg_obo_LO::ID." FROM ".cfg_obo_LO::TABLE."  WHERE ".cfg_obo_LO::VER." > '0' AND ".cfg_obo_LO::SUB_VER." = '0' AND ".cfg_obo_LO::ROOT_LO." NOT IN (SELECT ".cfg_obo_Perm::ITEM." FROM ".cfg_obo_Perm::TABLE." WHERE `".cfg_obo_Perm::TYPE."`='l')";
		$q = $this->DBM->query($qstr);
		while($r = $this->DBM->fetch_obj($q))
		{
			$lo = new nm_los_LO();
			if($lo->dbGetFull($this->DBM, $r->{cfg_obo_LO::ID}))
			{
				
				$moveLO = "INSERT IGNORE INTO ".cfg_obo_LO::DEL_TABLE." SET
						".cfg_obo_LO::ID." = '?',
						".cfg_obo_LO::TITLE." = '?',
						".cfg_obo_LO::VER." = '?',
						".cfg_obo_LO::ROOT_LO." = '?',
						".cfg_obo_LO::PARENT_LO." = '?',
						".cfg_obo_LO::TIME." = '?',
						".cfg_obo_LO::DEL_DATA." = COMPRESS('?')";
				
				if($this->DBM->querySafe($moveLO, $lo->loID, $lo->title, $lo->version, $lo->rootID, $lo->parentID, $lo->createTime, base64_encode(serialize($lo)) ))
				{
					$moveCount++;
				}
				else
				{
					trace('failed to move ' . print_r($lo, true), true);
				}				
			}
		
		}
		trace('time: ' . (microtime(true) - $t) .' moved permless MASTERS :' . $moveCount, true);
	 	if($moveCount == $this->DBM->fetch_num($q))
		{
			// Removes all los with no perms
			$t = microtime(true);
			// TODO: abstract perms
			$qstr = "DELETE FROM ".cfg_obo_LO::TABLE." WHERE ".cfg_obo_LO::ROOT_LO." NOT IN (SELECT ".cfg_obo_Perm::ITEM." FROM ".cfg_obo_Perm::TABLE." WHERE `".cfg_obo_Perm::TYPE."`='l')";
			if(! $this->DBM->query($qstr)) // no need for querysafe
			{
				$this->DBM->rollback();
				trace(mysql_error(), true);
			}
			trace('time: ' . (microtime(true) - $t) .' deleted permless los :' . $this->DBM->affected_rows(), true);
		}
	}

	public function cleanInstances()
	{
	   // move ownerless instances to deleted table
	   // los deleted above
		$t = microtime(true);
		$SM = nm_los_ScoreManager::getInstance();

		$qstr = "SELECT * FROM ".cfg_obo_Instance::TABLE." WHERE ".cfg_obo_Instance::ID." NOT IN (SELECT ".cfg_obo_Perm::ITEM." FROM ".cfg_obo_Perm::TABLE." WHERE `".cfg_obo_Perm::TYPE."`='i')";
		if($q = $this->DBM->query($qstr))
		{
			$moveFailed = false;
			while($r = $this->DBM->fetch_obj($q))
			{
				
				$scoredata = $SM->buildInstanceScoresObject($r->{cfg_obo_Instance::ID});
				$scoredata = base64_encode(serialize($scoredata));
				$qstr = "INSERT INTO ".cfg_obo_Instance::DELETED_TABLE." SET 
						".cfg_obo_Instance::ID." = '?',
						".cfg_obo_Instance::TITLE." = '?',
						".cfg_obo_LO::ID." = '?',
						".cfg_core_User::ID." = '?',
						".cfg_obo_Instance::TIME." = '?',
						".cfg_obo_Instance::COURSE." = '?',
						".cfg_obo_Instance::START_TIME." = '?',
						".cfg_obo_Instance::END_TIME." = '?',
						".cfg_obo_Instance::ATTEMPT_COUNT." = '?',
						".cfg_obo_Instance::SCORE_METHOD." = '?',
						".cfg_obo_Instance::SCORE_IMPORT." = '?',
						".cfg_obo_Course::ID." = '?',
						".cfg_obo_Instance::DELETED_SCORE_DATA." = '?'";
				$moveFailed = $this->DBM->querySafe($qstr, $r->{cfg_obo_Instance::ID}, $r->{cfg_obo_Instance::TITLE}, $r->{cfg_obo_LO::ID}, $r->{cfg_core_User::ID}, $r->{cfg_obo_Instance::TIME}, $r->{cfg_obo_Instance::COURSE}, $r->{cfg_obo_Instance::START_TIME}, $r->{cfg_obo_Instance::END_TIME}, $r->{cfg_obo_Instance::ATTEMPT_COUNT}, $r->{cfg_obo_Instance::SCORE_METHOD}, $r->{cfg_obo_Instance::SCORE_IMPORT}, $r->{cfg_obo_Course::ID}, $scoredata);
			}
			// only delete the instances if they were succusfully moved to the deleted table
			if($moveFailed == false)
			{
				$qstr = "DELETE FROM ".cfg_obo_Instance::TABLE." WHERE ".cfg_obo_Instance::ID." NOT IN (SELECT ".cfg_obo_Perm::ITEM." FROM ".cfg_obo_Perm::TABLE." WHERE `".cfg_obo_Perm::TYPE."`='i')";
				if(! $this->DBM->query($qstr)) // no need for querysafe
				{
					$this->DBM->rollback();
					trace(mysql_error(), true);
				}
				trace('time: ' . (microtime(true) - $t) .' moved permless instances :' . $this->DBM->affected_rows(), true);
			}
		}
	}

	public function cleanContentPages()
	{
		// Removes mappings for pages mapped to a non-existant learning object
		$t = microtime(true);
		$qstr = "DELETE M.* FROM
		".cfg_obo_Page::MAP_TABLE." AS M
		LEFT JOIN ".cfg_obo_LO::TABLE." AS L
		ON L.".cfg_obo_LO::ID." = M.".cfg_obo_LO::ID."
		WHERE L.".cfg_obo_LO::ID." IS NULL;";		
		if(! $this->DBM->query($qstr)) // no need for querysafe
		{
			$this->DBM->rollback();
			trace(mysql_error(), true);
		}
		trace('time: ' . (microtime(true) - $t) .' deleted lo_map_pages :' . $this->DBM->affected_rows(), true);	
		
		// Removes pages with no mapping 
		$t = microtime(true);
		$qstr = "DELETE P.* FROM
		".cfg_obo_Page::TABLE." AS P
		LEFT JOIN ".cfg_obo_Page::MAP_TABLE." AS M
		ON M.".cfg_obo_Page::ID." = P.".cfg_obo_Page::ID."
		WHERE M.".cfg_obo_Page::ID." IS NULL;";
		if(!$this->DBM->query($qstr)) // no need for querysafe
		{
			$this->DBM->rollback();
			trace(mysql_error(), true);
		}
		trace('time: ' . (microtime(true) - $t) .' deleted lo_pages :' . $this->DBM->affected_rows(), true);
		
	
	}

	public function cleanQGroups()
	{
		// Removes Learning Object Groups (Agroups and Pgroups) with no matching lo left
		$t = microtime(true);
		$qstr = "SELECT Q.".cfg_obo_QGroup::ID." FROM
		".cfg_obo_QGroup::TABLE." AS Q
		LEFT JOIN ".cfg_obo_LO::TABLE." AS L
		ON L.".cfg_obo_LO::PGROUP." = Q.".cfg_obo_QGroup::ID." 
		WHERE L.".cfg_obo_LO::ID." IS NULL AND Q.".cfg_obo_QGroup::ID." IN(
			SELECT Q.".cfg_obo_QGroup::ID." FROM
			".cfg_obo_QGroup::TABLE." AS Q
			LEFT JOIN ".cfg_obo_LO::TABLE." AS L
			ON L.".cfg_obo_LO::AGROUP." = Q.".cfg_obo_QGroup::ID."
			WHERE L.".cfg_obo_LO::ID." IS NULL
		)";		
		if(!$q = $this->DBM->query($qstr))
		{
			$this->DBM->rollback();
			trace(mysql_error(), true);
			//exit;
		}
		else
		{
			while($r = $this->DBM->fetch_obj($q))
			{
				$this->DBM->query("DELETE FROM ".cfg_obo_QGroup::TABLE." WHERE ".cfg_obo_QGroup::ID."='".$r->{cfg_obo_QGroup::ID}."'");
			}
			trace('time: ' . (microtime(true) - $t) .' deleted lo_qgroups  :' . $this->DBM->fetch_num($q), true);
		}
		
		// Removes all qgroup mappings to missing qgroups
		$t = microtime(true);
		$qstr = "DELETE M.* FROM
		".cfg_obo_QGroup::MAP_TABLE." AS M
		LEFT JOIN ".cfg_obo_QGroup::TABLE." AS Q
		ON M.".cfg_obo_QGroup::ID." = Q.".cfg_obo_QGroup::ID."
		WHERE Q.".cfg_obo_QGroup::ID." IS NULL;";
		if(!$this->DBM->query($qstr))
		{
			$this->DBM->rollback();
			trace(mysql_error(), true);
			//exit;
		}
		trace('time: ' . (microtime(true) - $t) .' deleted lo_map_qgroup :' . $this->DBM->affected_rows(), true);
	}


	
	// Removes all question scores associated with a missing qgroup
	public function cleanScores()
	{
		/*
		$t = microtime(true);
		$qstr = "DELETE lo_qscores.* FROM
		lo_qscores
		LEFT JOIN lo_qgroups
		ON lo_qscores.qgroup = lo_qgroups.id
		WHERE lo_qgroups.id IS NULL;";
		if(!$this->DBM->query($qstr))
		{
			$this->DBM->rollback();
			trace(mysql_error(), true);
			//exit;
		}		
		trace('time: ' . (microtime(true) - $t) .' deleted lo_qscores :' . $this->DBM->affected_rows(), true);			
		*/
		
		// missing qgroups are kept in the serialized deleted lo table, shouldn't delete scores ever
	}
		
	public function cleanAuthors()
	{
		// Removes all author mapping for missing learning objects
		$t = microtime(true);
		$qstr = "DELETE A.* FROM
		".cfg_obo_LO::MAP_AUTH_TABLE." AS A
		LEFT JOIN ".cfg_obo_LO::TABLE." AS L
		ON L.".cfg_obo_LO::ID." = A.".cfg_obo_LO::ID."
		WHERE L.".cfg_obo_LO::ID." IS NULL;";		
		if(!$this->DBM->query($qstr))
		{
			$this->DBM->rollback();
			trace(mysql_error(), true);
			//exit;
		}
		trace('time: ' . (microtime(true) - $t) .' deleted lo_map_authors :' . $this->DBM->affected_rows(), true);
	}

	public function cleanPerms()
	{
		// TODO:perms
		// Removes all permissions mapped to a missing learning object		
		$t = microtime(true);
		$qstr = "DELETE P.* FROM
		".cfg_obo_Perm::TABLE." AS P
		LEFT JOIN ".cfg_obo_LO::TABLE." As L
		ON L.".cfg_obo_LO::ID." = P.".cfg_obo_Perm::ITEM."
		WHERE L.".cfg_obo_LO::ID." IS NULL AND P.".cfg_obo_Perm::TYPE." = 'l';";
		if(!$this->DBM->query($qstr))
		{
			$this->DBM->rollback();
			trace(mysql_error(), true);
			//exit;
		}
		trace('time: ' . (microtime(true) - $t) .' deleted lo_map_perms :' . $this->DBM->affected_rows(), true);
		
		// Removes all perms to instances with no lerning object
		$t = microtime(true);
		$qstr = "DELETE P.* FROM
		".cfg_obo_Perm::TABLE." AS P
		LEFT JOIN ".cfg_obo_Instance::TABLE." AS I
		ON I.".cfg_obo_Instance::ID." = P.".cfg_obo_Perm::ITEM."
		WHERE I.".cfg_obo_Instance::ID." IS NULL AND P.".cfg_obo_Perm::TYPE." = 'i';";
		if(!$this->DBM->query($qstr))
		{
			$this->DBM->rollback();
			trace(mysql_error(), true);
			//exit;
			return false;
		}
		trace('time: ' . (microtime(true) - $t) .' deleted lo_map_perms :' . $this->DBM->affected_rows(), true);
	}

	public function cleanKeywords()
	{
		// Removes all keyword mappings with no lo
		$t = microtime(true);
		$qstr = "DELETE K.* FROM
		".cfg_obo_Keyword::MAP_TABLE." AS K
		LEFT JOIN ".cfg_obo_LO::TABLE." AS L
		ON L.".cfg_obo_LO::ID." = K.".cfg_obo_Keyword::MAP_ITEM."
		WHERE L.".cfg_obo_LO::ID." IS NULL AND K.".cfg_obo_Keyword::MAP_TYPE." = 'l';";
		if(!$this->DBM->query($qstr))
		{
			$this->DBM->rollback();
			trace(mysql_error(), true);
			//exit;
			return false;
		}
		trace('time: ' . (microtime(true) - $t) .' deleted lo_map_keywords :' . $this->DBM->affected_rows(), true);

		// Removes all keywords with no mapping
		$t = microtime(true);
		$qstr = "DELETE K.* FROM
		".cfg_obo_Keyword::TABLE." AS K
		LEFT JOIN ".cfg_obo_Keyword::MAP_TABLE." AS M
		ON M.".cfg_obo_Keyword::ID." = K.".cfg_obo_Keyword::ID."
		WHERE M.".cfg_obo_Keyword::ID." IS NULL;";
		if(!$this->DBM->query($qstr))
		{
			$this->DBM->rollback();
			trace(mysql_error(), true);
			//exit;
			return false;
		}
		trace('time: ' . (microtime(true) - $t) .' deleted lo_keywords :' . $this->DBM->affected_rows(), true);
	}
	
	public function cleanTracking()
	{
		/*
		// Removes all instance based tracking calls from instances that no longer exist
		$t = microtime(true);
		$qstr = "DELETE lo_tracking.* FROM
		lo_tracking
		LEFT JOIN lo_instances
		ON lo_instances.id = lo_tracking.instID
		WHERE lo_instances.id IS NULL AND lo_tracking.instID != 0;";
		if(!$this->DBM->query($qstr))
		{
			$this->DBM->rollback();
			trace(mysql_error(), true);
			//exit;
			return false;
		}
		trace('time: ' . (microtime(true) - $t) .' deleted lo_tracking :' . $this->DBM->affected_rows(), true);
		*/
		// The tracking data is too important to delete
	}
	
	public function cleanVisits()
	{
		/*
		// Removes all visits with no instances
		$t = microtime(true);
		$qstr = "DELETE lo_visits.* FROM
		lo_visits
		LEFT JOIN lo_instances
		ON lo_instances.id = lo_visits.instID
		WHERE lo_instances.id IS NULL;";
		if(!$this->DBM->query($qstr))
		{
			$this->DBM->rollback();
			trace(mysql_error(), true);
			//exit;
			return false;
		}
		trace('time: ' . (microtime(true) - $t) .' deleted lo_visits :' . $this->DBM->affected_rows(), true);
		
		// removes all attempts with no visit
		$t = microtime(true);
		$qstr = "DELETE ".cfg_obo_Attempt::TABLE.".* FROM
		".cfg_obo_Attempt::TABLE."
		LEFT JOIN lo_visits
		ON lo_visits.id = ".cfg_obo_Attempt::TABLE.".visitID
		WHERE lo_visits.id IS NULL;";
		if(!$this->DBM->query($qstr))
		{
			$this->DBM->rollback();
			trace(mysql_error(), true);
			//exit;
			return false;
		}
		trace('time: ' . (microtime(true) - $t) .' deleted lo_attempts :' . $this->DBM->affected_rows(), true);
		*/
		// Dont do this either
	}
	
	public function cleanLocks()
	{
		// removes all locks with no learning object
		$t = microtime(true);
		$qstr = "DELETE L.* FROM
		".cfg_obo_Lock::TABLE." AS L
		LEFT JOIN ".cfg_obo_LO::TABLE." AS LO
		ON LO.".cfg_obo_LO::ID." = L.".cfg_obo_LO::ID."
		WHERE LO.".cfg_obo_LO::ID." IS NULL;";
		if(!$this->DBM->query($qstr))
		{
			$this->DBM->rollback();
			trace(mysql_error(), true);
			//exit;
			return false;
		}
		trace('time: ' . (microtime(true) - $t) .' deleted lo_locks :' . $this->DBM->affected_rows(), true);
	}
	/**
	 * Clears Cached Learning Objects of a certain age
	 *
	 * @param $oldestTimestamp (int)  All cache created before this timestamp will be removed, default (0) will use config->cacheLife
	 * @return void
	 * @author Ian Turgeon
	 */
	public function purgeLOCache($oldestTimestamp=0)
	{
		// $t = microtime(true);
		// 
		// if(!nm_los_Validator::isPosInt($oldestTimestamp))
		// {
		// 	
		// 	$oldestTimestamp = time() - AppCfg::CACHE_LIFE;
		// }
		// // delete old cache before $oldestTimestamp
		// $this->DBM->querySafe("DELETE FROM ".cfg_obo_Cache::LO_TABLE." WHERE ".cfg_obo_Cache::LO_TIME." < '?'", $oldestTimestamp);
		// 
		// // removes all cache with no learning object
		// // 0.0014 s
		// $t = microtime(true);
		// $qstr = "DELETE C.* FROM
		// ".cfg_obo_Cache::LO_TABLE." AS C
		// LEFT JOIN ".cfg_obo_LO::TABLE." AS L
		// ON L.".cfg_obo_LO::ID." = C.".cfg_obo_Cache::LO_ID."
		// WHERE L.".cfg_obo_LO::ID." IS NULL;";
		// if(!$this->DBM->query($qstr))
		// {
		// 	$this->DBM->rollback();
		// 	trace(mysql_error(), true);
		// 	//exit;
		// 	return false;
		// }
		// trace('time: ' . (microtime(true) - $t) .' deleted cache :' . $this->DBM->affected_rows(), true);
		
		
	}
	
	protected function _mergeUsersUpdate($tableName, $qSuffex)
	{
		$return = $this->DBM->querySafe("UPDATE IGNORE $tableName $qSuffex");
		trace("UPDATE $tableName $qSuffex");
		trace("merge user: $tableName - count:" . $this->DBM->affected_rows());
		return $return;
	}
	
	public function mergeUsers($userIDFrom, $userIDTo)
	{
		
		$RM = nm_los_RoleManager::getInstance();
		if($RM->isSuperUser())
		{
			$this->defaultDBM();
			// TODO: make sure they are su
			
			$AM = core_auth_AuthManager::getInstance();
		
			$fromUser = $AM->fetchUserByID($userIDFrom);
			$toUser = $AM->fetchUserByID($userIDTo);
			
			if( !($fromUser instanceof core_auth_User) || !($toUser instanceof core_auth_User) )
			{
		       
		        return core_util_Error::getError(2);
			}
			$this->DBM->startTransaction();
			$q2 = "SET ".cfg_core_User::ID." = '$userIDTo' WHERE ".cfg_core_User::ID." = '$userIDFrom'";
			$success = true;
			// update lo_ansers
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_Answer::TABLE, $q2);
			// update lo_attempts
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_Attempt::TABLE, $q2);
			// update lo_attempts_extra
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_ExtraAttempt::TABLE, $q2);
			// lo_computer_data
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_ComputerData::TABLE, $q2);
			// lo_instances
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_Instance::TABLE, $q2);
			// lo_instances_deleted
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_Instance::DELETED_TABLE, $q2);
			// lo_locks
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_Lock::TABLE, $q2);
			// lo_map_authors
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_LO::MAP_AUTH_TABLE, $q2);
			// lo_map_perms
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_Perm::TABLE, $q2);
			// lo_map_roles
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_Role::MAP_USER_TABLE, $q2);
			// lo_media
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_Media::TABLE, $q2);
			// lo_pages
			//$success = $success && $this->_mergeUsersUpdate(cfg_obo_Page::TABLE, $q2);
			// lo_qgroups
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_QGroup::TABLE, $q2);
			// lo_questions
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_Question::TABLE, $q2);
			// lo_tracking
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_Track::TABLE, $q2);
			// lo_visits
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_Visit::TABLE, $q2);
			// lo_perms
			$success = $success && $this->_mergeUsersUpdate(cfg_obo_Perm::TABLE, $q2);

			
			if(!$success)
			{
				$this->DBM->rollBack();
			}
			else
			{
				$this->DBM->commit();
				// clear all cache
				
				core_util_Cache::getInstance()->clearAllCache();
				// remove old user
				$AM->removeUser($userIDFrom);
				$TM = nm_los_TrackingManager::getInstance();
				$TM->trackMergeUser($userIDFrom, $userIDTo);
			}
			return $success;
		}
       
        return core_util_Error::getError(4);
	}
	
}
?>
<?php
require_once("module.php");
require_once("items.php");
require_once("notes.php");
require_once("media.php");

class Players extends Module
{	
    public function createPlayer($strNewUserName, $strPassword, $strFirstName, $strLastName, $strEmail, $strGroup = '')
    {
        $strNewUserName = addslashes($strNewUserName);	
        $strFirstName = addslashes($strFirstName);	
        $strLastName = addslashes($strLastName);	
        $strEmail = addslashes($strEmail);	

        $query = "SELECT player_id FROM players 
            WHERE user_name = '{$strNewUserName}' LIMIT 1";

        if ($obj = mysql_fetch_object(Module::query($query))) {
            return new returnData(1, $obj->player_id, 'user exists');
        }

        $query = "INSERT INTO players (user_name, password, 
            first_name, last_name, email, created, group_name) 
            VALUES ('{$strNewUserName}', MD5('$strPassword'),
                    '{$strFirstName}','{$strLastName}','{$strEmail}', NOW(), '{$strGroup}')";

        Module::query($query);
        if (mysql_error()) return new returnData(3, NULL, 'SQL Error');

        return new returnData(0, mysql_insert_id());
    }

    public function movePlayerToGroup($intPlayerId, $strGroup)
    {
        $query = "UPDATE players SET group_name = '{$strGroup}' WHERE player_id = '{$intPlayerId}'";
        Module::query($query);
        return new returnData(0);
    }

    public function deletePlayer($strUserName, $strPassword)
    {
        $strUserName = addslashes($strUserName);	

        $query = "DELETE FROM players WHERE user_name = '".$strUserName."' AND password = MD5('$strPassword')";
        Module::query($query);
        if (mysql_error()) return new returnData(3, NULL, 'SQL Error');

        return new returnData(0);
    }

    public function loginPlayer($strUser,$strPassword)
    {
        $query = "SELECT * FROM players 
            WHERE user_name = '{$strUser}' and password = MD5('{$strPassword}') LIMIT 1";
        $rs = Module::query($query);
        if (mysql_num_rows($rs) < 1) return new returnData(0, NULL, 'bad username or password');
        $player = @mysql_fetch_object($rs);
        Module::appendLog($player->player_id, 0, Module::kLOG_LOGIN);//Only place outside of Module's EVENT_PIPELINE that can append the Log
        return new returnData(0, intval($player->player_id));
    }

    /*
     * Should be the new way to log in. The above function doesn't return a robust, expandible, json package. It returns just an int.
     * Because of this, the parsers using it try to parse an int directly from the return data, disallowing the attachment of more data.
     */
    public function getLoginPlayerObject($strUser,$strPassword)
    {
        $query = "SELECT player_id, user_name, display_name, media_id  FROM players 
            WHERE user_name = '{$strUser}' and password = MD5('{$strPassword}') LIMIT 1";
        $rs = Module::query($query);
        if (mysql_num_rows($rs) < 1) return new returnData(0, NULL, 'bad username or password');
        $player = @mysql_fetch_object($rs);
        Module::appendLog($player->player_id, 0, Module::kLOG_LOGIN);//Only place outside of Module's EVENT_PIPELINE that can append the Log
        return new returnData(0, $player);
    }

    public function createPlayerAndGetLoginPlayerObject($strGroup)
    {
        $newPlayer->returnCode = 1;
        $userName;
        while($newPlayer->returnCode == 1)
        {
            $userName = $strGroup."_".Players::rand_string(5);
            $newPlayer = Players::createPlayer($userName, $strGroup, $strGroup."-player", '', '', $strGroup);
        }

        if($newPlayer)
            return Players::getLoginPlayerObject($userName,$strGroup);

        return new returnData(1, NULL, 'error...');
    }

    public function updatePlayerLastGame($intPlayerID, $gameId)
    {
        $query = "UPDATE players
            SET last_game_id = '{$gameId}'
            WHERE player_id = {$intPlayerID}";

        Module::query($query);

        if (mysql_error()) return new returnData(3, NULL, "SQL Error");
        if (mysql_affected_rows()) return new returnData(0, TRUE);
        else return new returnData(0, FALSE);
    }	

    function addPlayerPicFromFileName($playerId, $filename, $name)
    {
        if(!$name) $name = $playerId."_player_pic";
        $newMediaResultData = Media::createMedia("player", $name, $filename, 0);
        $newMediaId = $newMediaResultData->data->media_id;
        Players::addPlayerPic($playerId, $newMediaId);
        $returnObj->media_id = $newMediaId;
        return new returnData(0,$returnObj);
    }

    function addPlayerPic($playerId, $mediaId)
    {
        $query = "UPDATE players SET media_id = {$mediaId} WHERE player_id = {$playerId}";
        Module::query($query);
        return new returnData(0);
    }

    function updatePlayerNameMedia($playerId, $name, $mediaId = 0)
    {
        if($mediaId > 0)
            $query = "UPDATE players SET display_name = '{$name}', media_id = {$mediaId} WHERE player_id = {$playerId}";
        else
            $query = "UPDATE players SET display_name = '{$name}' WHERE player_id = {$playerId}";
        Module::query($query);
        return new returnData(0);
    }

    public function getPlayers()
    {
        $query = "SELECT player_id, user_name, latitude, longitude FROM players";

        $rs = Module::query($query);
        return new returnData(0, $rs);
    }

    public function getPlayersForGame($gameId)
    {
        $query = "SELECT player_id, user_name, latitude, longitude FROM players 
            WHERE last_game_id = '{$gameId}'";

        $rs = Module::query($query);
        return new returnData(0, $rs);
    }

    public function getOtherPlayersForGame($gameId, $intPlayerID)
    {
        $timeLimitInMinutes = 20;

        $query = "SELECT p.player_id, p.media_id, p.user_name, p.display_name, pl.event_detail_1 as 'latitude', pl.event_detail_2 as 'longitude', pl.timestamp FROM (SELECT player_id, event_detail_1, event_detail_2, timestamp FROM player_log WHERE game_id = '{$gameId}' AND event_type = 'MOVE' AND deleted = 0  AND timestamp >= (CURDATE() - INTERVAL '{$timeLimitInMinutes}' MINUTE) GROUP BY player_id ORDER BY timestamp DESC) AS pl JOIN (SELECT * FROM players WHERE players.player_id != '{$intPlayerID}' AND players.show_on_map = '1')  AS p ON pl.player_id = p.player_id";

        $rs = Module::query($query);


        $array = array();
        while ($object = mysql_fetch_object($rs)) {
            $array[] = $object;
        }

        return new returnData(0, $array);
    }

    public function startOverGameForPlayer($gameId, $intPlayerID)
    {	
        $query = "DELETE FROM player_items WHERE player_id = '{$intPlayerID}' AND game_id = {$gameId}";		
        Module::query($query);
        if (mysql_error()) return new returnData(3, NULL, "SQL Error");

        $query = "UPDATE player_log
            SET deleted = 1
            WHERE player_id = '{$intPlayerID}' AND game_id = '{$gameId}'";		
            Module::query($query);
        if (mysql_error()) return new returnData(3, NULL, "SQL Error");

        $gameReturnData = Games::getGame($gameId);
        $game = $gameReturnData->data;
        if ($game->delete_player_locations_on_reset) {

            $query = "SELECT item_id FROM items WHERE game_id = {$gameId} AND creator_player_id = {$intPlayerID}";	
            $itemsRs = Module::query($query);
            if (mysql_error()) return new returnData(3, NULL, "SQL Error");

            while ($item = @mysql_fetch_object($itemsRs)) {			
                $query = "DELETE FROM locations
                    WHERE game_id = {$gameId} AND locations.type = 'Item' 
                    AND locations.type_id = '{$item->item_id}'";
                Module::query($query);
            }	
        }	

        if (mysql_affected_rows()) return new returnData(0, TRUE);
        else return new returnData(0, FALSE);
    }	

    public function updatePlayerLocation($intPlayerID, $gameId, $floatLat, $floatLong)
    {
        Module::processGameEvent($intPlayerID, $gameId, Module::kLOG_MOVE, $floatLat, $floatLong);
        if (mysql_affected_rows()) return new returnData(0, TRUE);
        else return new returnData(0, FALSE);
    }

    public function nodeViewed($gameId, $intPlayerID, $intNodeID, $intLocationID = 0)
    {	
        //Module::applyPlayerStateChanges($gameId, $intPlayerID, Module::kLOG_VIEW_NODE, $intNodeID); //Was causing duplicate playerStateChanges (changed 5/23/12 Phil)
        Module::processGameEvent($intPlayerID, $gameId, Module::kLOG_VIEW_NODE, $intNodeID, $intLocationID);

        return new returnData(0, TRUE);
    }

    public function setItemCountForPlayerJSON($obj)
    {
        $gameId = $obj['gameId'];
        $intItemID = $obj['itemId'];
        $intPlayerID = $obj['playerId'];
        $qty = $obj['qty'];

        Module::setItemCountForPlayer($gameId, $intItemID, $intPlayerID, $qty);
    }

    public function setItemCountForPlayer($gameId, $intItemID, $intPlayerID, $qty)
    {
        $rData = Module::setItemCountForPlayer($gameId, $intItemID, $intPlayerID, $qty);
        if(!$rData->returnCode)
            return new returnData(0, $rData);
        else
            return $rData;
    }

    public function giveItemToPlayer($gameId, $intItemID, $intPlayerID, $qtyToGive=1) 
    {
        $rData = Module::giveItemToPlayer($gameId, $intItemID, $intPlayerID, $qtyToGive=1);
        if(!$rData->returnCode)
            return new returnData(0, $rData);
        else
            return $rData;
    }

    public function takeItemFromPlayer($gameId, $intItemID, $intPlayerID, $qtyToGive=1) 
    {
        $rData = Module::takeItemFromPlayer($gameId, $intItemID, $intPlayerID, $qtyToGive=1);
        if(!$rData->returnCode)
            return new returnData(0, $rData);
        else
            return $rData;
    }

    public function itemViewed($gameId, $intPlayerID, $intItemID, $intLocationID = 0)
    {
        Module::processGameEvent($intPlayerID, $gameId, Module::kLOG_VIEW_ITEM, $intItemID, $intLocationID);

        $query = "UPDATE player_items SET viewed = 1 WHERE game_id = {$gameId} AND player_id = {$intPlayerID} AND item_id = {$intItemID}";


        Module::query($query);

        if (mysql_error()) return new returnData(3, NULL, "SQL Error");
        if (mysql_affected_rows()) return new returnData(0, TRUE);
        else return new returnData(0, FALSE);

        return new returnData(0, TRUE);
    }

    public function npcViewed($gameId, $intPlayerID, $intNpcID, $intLocationID = 0)
    {	
        Module::processGameEvent($intPlayerID, $gameId, Module::kLOG_VIEW_NPC, $intNpcID, $intLocationID);

        return new returnData(0, TRUE);
    }

    public function webPageViewed($gameId, $intPlayerID, $intWebPageID, $intLocationID = 0)
    {	
        Module::processGameEvent($intPlayerID, $gameId, Module::kLOG_VIEW_WEBPAGE, $intWebPageID, $intLocationID);

        return new returnData(0, TRUE);
    }

    public function augBubbleViewed($gameId, $intPlayerID, $intAugBubbleID, $intLocationID = 0)
    {	
        Module::processGameEvent($intPlayerID, $gameId, Module::kLOG_VIEW_AUGBUBBLE, $intAugBubbleID, $intLocationID);

        return new returnData(0, TRUE);
    }

    public function pickupItemFromLocation($gameId, $intPlayerID, $intItemID, $intLocationID, $qty=1)
    {	
        $query = "SELECT item_qty from locations WHERE game_id = {$gameId} AND location_id = $intLocationID";
        $result = Module::query($query);
        $loc = mysql_fetch_object($result);

        if($loc->item_qty != -1 && $loc->item_qty < $qty){
            if($loc->item_qty == 0){
                return new returnData(0, FALSE, "Location has qty 0");
            }

            $qtyGiven = Module::giveItemToPlayer($gameId, $intItemID, $intPlayerID, $loc->item_qty);
            Module::decrementItemQtyAtLocation($gameId, $intLocationID, $qtyGiven); 

            return new returnData(0, $qtyGiven, "Location has qty 0");
        }

        $qtyGiven = Module::giveItemToPlayer($gameId, $intItemID, $intPlayerID, $qty);
        Module::decrementItemQtyAtLocation($gameId, $intLocationID, $qtyGiven); 

        return new returnData(0, TRUE);

    }

    public function dropItem($gameId, $intPlayerID, $intItemID, $floatLat, $floatLong, $qty=1)
    {
        Module::takeItemFromPlayer($gameId, $intItemID, $intPlayerID, $qty);
        Players::giveItemToWorld($gameId, $intItemID, $floatLat, $floatLong, $qty);

        return new returnData(0, FALSE);
    }		

    public function dropNote($gameId, $intPlayerID, $noteID, $floatLat, $floatLong)
    {
        Module::giveNoteToWorld($gameId, $noteID, $floatLat, $floatLong);

        return new returnData(0, FALSE);
    }	

    public function destroyItem($gameId, $intPlayerID, $intItemID, $qty=1)
    {
        Module::takeItemFromPlayer($gameId, $intItemID, $intPlayerID, $qty);
        Module::processGameEvent($intPlayerID, $gameId, Module::kLOG_DESTROY_ITEM, $intItemID, $qty);

        return new returnData(0, FALSE);
    }		

    public function mapViewed($gameId, $intPlayerID)
    {
        Module::processGameEvent($intPlayerID, $gameId, Module::kLOG_VIEW_MAP);
        return new returnData(0, FALSE);
    }

    public function questsViewed($gameId, $intPlayerID)
    {
        Module::processGameEvent($intPlayerID, $gameId, Module::kLOG_VIEW_QUESTS);
        return new returnData(0, FALSE);
    }

    public function inventoryViewed($gameId, $intPlayerID)
    {
        Module::processGameEvent($intPlayerID, $gameId, Module::kLOG_VIEW_INVENTORY);
        return new returnData(0, FALSE);
    }			

    function setShowPlayerOnMap($playerId, $spom)
    {
        $query = "UPDATE players SET show_on_map = '{$spom}' WHERE player_id = '{$playerId}'";
        Module::query($query);
        return new returnData(0);
    }

    public function resetAndEmailNewPassword($strEmail)
    {
        $amppos = strrpos($strEmail, "@");
        if($amppos === false) {
            $query2 = "SELECT * FROM players WHERE user_name = '{$strEmail}'";   
        }
        else 
            $query2 = "SELECT * FROM players WHERE email = '{$strEmail}'";

        $result = Module::query($query2);
        if (!$player = mysql_fetch_array($result)) return new returnData(4, NULL, "Not a player");

        $playerid = $player['player_id'];
        $username = $player['user_name'];
        $email = $player['email'];
        $scrambledpassword = MD5($player['password']);

        //email it to them
        $subject = "ARIS Password Request";
        $body = "We received a forgotten password request for your ARIS account. If you did not make this request, do nothing and your account info will not change. <br><br>To reset your password, simply click the link below. Please remember that passwords are case sensitive. If you are not able to click on the link, please copy and paste it into your web browser.<br><br> <a href='".Config::serverWWWPath."/resetpassword.php?t=p&i=$playerid&p=$scrambledpassword'>".Config::serverWWWPath."/resetpassword.php?t=p&i=$playerid&p=$scrambledpassword</a> <br><br> Regards, <br>ARIS";

        if (Module::sendEmail($email, $subject, $body)) return new returnData(0, NULL);
        else return new returnData(5, NULL, "Mail could not be sent");
    }

    public function changePassword($intPlayerID, $strOldPassword, $strNewPassword)
    {	
        if ($strOldPassword == $strNewPassword) return new returnData(0, NULL);

        $query = "UPDATE players 
            SET password = MD5('{$strNewPassword}')
            WHERE password = MD5('{$strOldPassword}')
            AND player_id = {$intPlayerID}";

        Module::query($query);

        if (mysql_affected_rows() < 1) return new returnData(4, NULL, 'No players exist with matching ID and password');
        return new returnData(0, NULL);
    }	

    // \/ \/ \/ BACKPACK FUNCTIONS \/ \/ \/

    /**
      Gets array of JSON encoded 'web backpacks', containing player information relating to items, attributes, and notes gained throughout a game. For an example of its use, see 'getBackPacksFromArray.html'.
      @param: bpReqObj- a JSON encoded object with two fields:
      gameId- An integer representing the game_id of the game information desired.
      playerArray- Either a JSON encoded array of integer player_ids of all the players whose information is desired, a single integer if only one player's information is desired, or nothing if all player information for an entire game is desired.
      @returns: On success, returns JSON encoded game object with a parameter containing an array of player objects with various parameters describing a player's information.
      If gameId is empty, returns 'Error- Empty Game' and aborts the function.
      If game with gameId does not exist, returns 'Error- Invalid Game Id' and aborts the function.
      If playerArray is anything other than the specified options, returns 'Error- Invalid Player Array' and aborts the function.
     **/
    public static function getPlayerBackpacksFromArray($bpReqObj)
    {
        //Module::serverErrorLog('Get Backpacks From Arrays Called: '.date_format(date_create(), 'H:i:s:u')."\n".$bpReqObj);
        $gameId = $bpReqObj['gameId'];
        $playerArray = $bpReqObj['playerArray'];
        $getItems = (isset($bpReqObj['items']) ? $bpReqObj['items'] : true); //Default true
        $getAttributes = (isset($bpReqObj['attributes']) ? $bpReqObj['attributes'] : true); //Default true
        $getNotes = (isset($bpReqObj['notes']) ? $bpReqObj['notes'] : true); //Default true

        if(is_numeric($gameId))
            $gameId = intval($gameId);
        else
            return new returnData(1, "Error- Empty Game ".$gameId);

        if(($game = Games::getDetailedGameInfo($gameId)) == "Invalid Game Id")
            return new returnData(1, "Error- Empty Game ".$gameId);

        if(is_null($playerArray))
        {
            $game->backpacks =  Players::getAllPlayerDataBP($gameId, $getItems, $getAttributes, $getNotes);
            return new returnData(0,$game);
        }
        else if(is_array($playerArray))
        {
            $game->backpacks =  Players::getPlayerArrayDataBP($gameId, $playerArray, $getItems, $getAttributes, $getNotes);
            return new returnData(0,$game);
        }
        else if(is_numeric($playerArray))
        {
            $game->backpacks = Players::getSinglePlayerDataBP($gameId, intval($playerArray), false, $getItems, $getAttributes, $getNotes);
            return new returnData(0,$game,true);
        }
        else
        {
            return new returnData(1, "Error- Invalid Player Array");
        }
    }

    private static function getAllPlayerDataBP($gameId, $getItems = true, $getAttributes = true, $getNotes = true)
    {
        $backPacks = array();
        $query = "SELECT DISTINCT player_id FROM player_log WHERE game_id='{$gameId}' AND player_id != 0";
        $result = Module::query($query);
        while($player = mysql_fetch_object($result))
            $backPacks[] = Players::getSinglePlayerDataBP($gameId, $player->player_id, false, $getItems, $getAttributes, $getNotes);
        return $backPacks;
    }

    private static function getPlayerArrayDataBP($gameId, $playerArray, $getItems = true, $getAttributes = true, $getNotes = true)
    {
        //Module::serverErrorLog('Get Player Array Data Called: '.date_format(date_create(), 'H:i:s:u')."\n".$playerArray);
        $backPacks = array();
        foreach($playerArray as $player)
            $backPacks[] = Players::getSinglePlayerDataBP($gameId, $player, false, $getItems, $getAttributes, $getNotes);
        return $backPacks;
    }

    /*
     * Gets information for web backpack for any player/game pair
     */
    private static function getSinglePlayerDataBP($gameId, $playerId, $individual=false, $getItems = true, $getAttributes = true, $getNotes = true)
    {
        //Module::serverErrorLog('Single Player Start: '.date_format(date_create(), 'H:i:s:u'));
        $backpack = new stdClass();

        //Get owner information
        $query = "SELECT user_name, display_name, group_name, media_id FROM players WHERE player_id = '{$playerId}'";
        $result = Module::query($query);
        $name = mysql_fetch_object($result);
        if(!$name) return "Invalid Player ID";
        $backpack->owner = new stdClass();
        $backpack->owner->user_name = $name->user_name;
        $backpack->owner->display_name = $name->display_name;
        $backpack->owner->group_name = $name->group_name;
        $backpack->owner->player_id = $playerId;
        $playerpic = Media::getMediaObject('player', $name->media_id)->data;
        if($playerpic)
            $backpack->owner->player_pic_url = $playerpic->url_path.$playerpic->file_path;
        else
            $backpack->owner->player_pic_url = null;

        /* ATTRIBUTES */
        //Module::serverErrorLog('Attributes    Start: '.date_format(date_create(), 'H:i:s:u'));
        if($getAttributes) $backpack->attributes = Items::getDetailedPlayerAttributes($playerId, $gameId);

        /* OTHER ITEMS */
        //Module::serverErrorLog('Items         Start: '.date_format(date_create(), 'H:i:s:u'));
        if($getItems) $backpack->items = Items::getDetailedPlayerItems($playerId, $gameId);

        /* NOTES */
        //Module::serverErrorLog('Notes         Start: '.date_format(date_create(), 'H:i:s:u'));
        if($getNotes) $backpack->notes = Notes::getDetailedPlayerNotes($playerId, $gameId, $individual);

        return $backpack;
    }

    /**
     * Create new accounts from an array of player objects
     * @param array $playerArrays JSON Object containing userNames and passwords as arrays {"userNames":["joey","mary"],"passwords":["fds2cd3","d3g5gg"]}
     * @return returnData
     * @returns a returnData object containing player objects with their assigned player ids
     * @see returnData
     */
    function createPlayerAccountsFromArrays($playerArrays)
    {		
        $usernameArray = $playerArrays['userNames'];
        $passwordArray = $playerArrays['passwords'];
        $firstnameArray = $playerArrays['firstNames'];
        $lastnameArray = $playerArrays['lastNames'];
        $emailArray = $playerArrays['emails'];

        if(count($usernameArray) == 0 || $usernameArray[0] == '' || count($usernameArray) != count($passwordArray))
            return new returnData(1, "", "Bad JSON or userNames and passwords arrays have different sizes");

        //Search for matching user names
        $query = "SELECT user_name FROM players WHERE ";
        for($i = 0; $i < count($usernameArray); $i++)
            $query = $query."user_name = '{$usernameArray[$i]}' OR ";
        $query = substr($query, 0, strlen($query)-4).";";

        $result = Module::query($query);

        $reterr = "username ";
        while($un = mysql_fetch_object($result))
            $reterr = $reterr.$un->user_name.", ";	
        if($reterr != "username ")
        {
            $reterr = substr($reterr, 0, strlen($query)-2)." already in database.";
            return new returnData(1, $reterr);
        }

        //Run the insert
        $query = "INSERT INTO players (user_name, password, first_name, last_name, email, created) VALUES ";
        for($i = 0; $i < count($usernameArray); $i++)
            $query = $query."('{$usernameArray[$i]}', MD5('$passwordArray[$i]'), '{$firstnameArray[$i]}','{$lastnameArray[$i]}','{$emailArray[$i]}', NOW()), ";
        $query = substr($query, 0, strlen($query)-2).";";
        $result = Module::query($query);
        if (mysql_error()) 	return new returnData(1, "","Error Inserting Records");


        //Generate the result
        $query = "SELECT player_id,user_name FROM players WHERE ";
        for($i = 0; $i < count($usernameArray); $i++)
            $query = $query."user_name = '{$usernameArray[$i]}' OR ";
        $query = substr($query, 0, strlen($query)-4).";";
        $result = Module::query($query);
        if (mysql_error()) 	return new returnData(1, "","Error Verifying Records");


        return new returnData(0,$result);
    }

    /**
     * Create new accounts from an array of player objects
     * @param array $playerArray Array of JSON formated player objects [{"username":"joey","password":"h5f3ad3","firstName":"joey","lastName":"smith","email":"joey@gmail.com"}]
     * @return returnData
     * @returns a returnData object containing player objects with their assigned player ids
     * @see returnData
     */
    function createPlayerAccountsFromObjectArray($playerArray)
    {
        //return new returnData($playerArray);
        if(count($playerArray) == 0)
            return new returnData(1, "Bad JSON or Empty Array");

        //Search for matching user names
        $query = "SELECT user_name FROM players WHERE ";
        for($i = 0; $i < count($playerArray); $i++)
            $query = $query."user_name = '{$playerArray[$i]["username"]}' OR ";
        $query = substr($query, 0, strlen($query)-4).";";
        //$query of form "SELECT user_name FROM players WHERE user_name = 'user1' OR user_name = 'user2' OR user_name = 'user3';"
        $result = Module::query($query);

        //Check if any duplicates exist
        $reterr = "Duplicate username(s): ";
        while($un = mysql_fetch_object($result))
            $reterr = $reterr.$un->user_name.", ";
        if($reterr != "Duplicate username(s): ")
        {
            $reterr = substr($reterr, 0, strlen($reterr)-2)." already in database.";
            return new returnData(4, "",$reterr);
        }

        //Run the insert
        $query = "INSERT INTO players (user_name, password, first_name, last_name, email, created) VALUES ";
        for($i = 0; $i < count($playerArray); $i++)
            $query = $query."('{$playerArray[$i]["username"]}', MD5('{$playerArray[$i]["password"]}'), '{$playerArray[$i]["firstName"]}','{$playerArray[$i]["lastName"]}','{$playerArray[$i]["email"]}', NOW()), ";
        $query = substr($query, 0, strlen($query)-2).";";
        $result = Module::query($query);
        if (mysql_error()) 	return new returnData(1, "","Error Inserting Records");

        //Generate the result
        $query = "SELECT player_id,user_name FROM players WHERE ";
        for($i = 0; $i < count($playerArray); $i++)
            $query = $query."user_name = '{$playerArray[$i]["username"]}' OR ";
        $query = substr($query, 0, strlen($query)-4).";";
        $result = Module::query($query);
        if (mysql_error()) 	return new returnData(1, "","Error Verifying Records");

        return new returnData(0,$result);
    }

    function getPlayerIdsForGroup($groupReqObj)
    {
        if(is_string($groupReqObj))
        {
            //Treat as string
            $query = "SELECT player_id FROM players WHERE group_name = '$groupReqObj';";

            $playersSQLObj = Module::query($query);
            $playersArray = array();
            while($playerId = mysql_fetch_object($playersSQLObj))
                $playersArray[] = $playerId->player_id;
            return new returnData(0,$playersArray);
        }
        else if($groupReqObj['group_name'])
        {
            $query = "SELECT player_id FROM players WHERE group_name = '{$groupReqObj['group_name']}';";

            $playersSQLObj = Module::query($query);
            $playersArray = array();
            while($playerId = mysql_fetch_object($playersSQLObj))
                $playersArray[] = $playerId->player_id;
            return new returnData(0,$playersArray);
        }
        else
            return new returnData(1,$groupReqObj,"Expecting JSON encoded string of form {'group_name':'theStringOfTheGroupYouAreLookingFor'}.");
    }

    function getPlayerLog($logReqObj)
    {
        $gameId = $logReqObj['gameId'];
        //Date format- YYYY-MM-DD HH:MM:SS
        $startDate = $logReqObj['startDate']; //<- This time represents the midnight between January 1st and January 2nd
        $endDate = $logReqObj['endDate']; //<- This time represents January 25 at 3:00 PM

        if(is_numeric($gameId))
            $gameId = intval($gameId);
        else
            return new returnData(1, "Error- Empty Game ".$gameId);

        $query = "SELECT * FROM player_log WHERE game_id = '{$gameId}' AND timestamp BETWEEN '{$startDate}' AND '{$endDate}'";
        $result = Module::query($query);

        $log = array();
        while($entry = mysql_fetch_object($result))
            $log[] = $entry;

        return new returnData(0,$log);
    }

    protected function giveItemToWorld($gameId, $intItemID, $floatLat, $floatLong, $intQty = 1)
    {
        //Find any items on the map nearby
        $clumpingRangeInMeters = 10;

        $query = "SELECT *,((ACOS(SIN($floatLat * PI() / 180) * SIN(latitude * PI() / 180) + 
            COS($floatLat * PI() / 180) * COS(latitude * PI() / 180) * 
            COS(($floatLong - longitude) * PI() / 180)) * 180 / PI()) * 60 * 1.1515) * 1609.344
            AS `distance`, location_id 
            FROM locations 
            WHERE type = 'item' AND type_id = '{$intItemID}' AND game_id = '{$gameId}'
            HAVING distance<= {$clumpingRangeInMeters}
        ORDER BY distance ASC"; 	
            $result = Module::query($query);

        if ($closestLocationWithinClumpingRange = @mysql_fetch_object($result)) {
            //We have a match

            $query = "UPDATE locations
                SET item_qty = item_qty + {$intQty}
            WHERE location_id = {$closestLocationWithinClumpingRange->location_id} AND game_id = '{$gameId}'";
            Module::query($query);
        }
        else {

            $itemName = $this->getItemName($gameId, $intItemID);
            $error = 100; //Use 100 meters
            $icon_media_id = $this->getItemIconMediaId($gameId, $intItemID); //Set the map icon = the item's icon

            $query = "INSERT INTO locations (game_id, name, type, type_id, icon_media_id, latitude, longitude, error, item_qty)
                VALUES ('{$gameId}', '{$itemName}','Item','{$intItemID}', '{$icon_media_id}', '{$floatLat}','{$floatLong}', '{$error}','{$intQty}')";
            Module::query($query);

            $newId = mysql_insert_id();
            //Create a coresponding QR Code
            QRCodes::createQRCode($gameId, "Location", $newId, '');
        }
    }
}
?>

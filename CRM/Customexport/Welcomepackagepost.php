<?php
/*-------------------------------------------------------+
| SYSTOPIA - Custom Export for Greenpeace                |
| Copyright (C) 2017 SYSTOPIA                            |
| Author: M. Wire (mjw@mjwconsult.co.uk)                 |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

class CRM_Customexport_WelcomepackagePost extends CRM_Customexport_Base {

  private $campaignExternalIdentifier; // Holds the external identifier for the campaign (used in SQL queries) from setting welcomepackagepost_campaign_externalidentifier

  function __construct($params) {
     if (!$this->getExportSettings('welcomepackagepost_exports')) {
      throw new Exception('Could not load welcomepackagepostExports settings - did you define a default value?');
    };
    $this->campaignExternalIdentifier = CRM_Customexport_Utils::getSettings('welcomepackagepost_campaign_externalidentifier');

    $this->getLocalFilePath();

    // params override setting, if param not specified read the setting
    if (!isset($params['create_activity'])) {
      $params['create_activity'] = CRM_Customexport_Utils::getSettings('welcomepackagepost_create_export_activity');
    }
    if (!isset($params['export_activity_subject'])) {
      $params['export_activity_subject'] = CRM_Customexport_Utils::getSettings('welcomepackagepost_export_activity_subject');
    }

    parent::__construct($params);
  }

  function export() {
    return parent::export();
  }

  public function createMassActivity($activity_params = array()) {
    $activity_params = array(
      'status_id'        => 'Completed',
      'activity_type_id' => 'Action',
      'subject'          => $this->params['export_activity_subject']);

    try {
      if ($this->campaignExternalIdentifier) {
        $campaign = civicrm_api3('Campaign', 'getsingle', array(
          'external_identifier' => $this->campaignExternalIdentifier,
          'return'              => 'title,id'));
        $activity_params['campaign_id'] = $campaign['id'];
      }

      parent::createMassActivity($activity_params);
    } catch (Exception $e) {
      CRM_Core_Error::debug_log_message('Customexport - Problem creating activity: ' . $e->getMessage());
    }
  }

  /**
   * The keys we need in the csv export.  These MUST exist in the sql select
   * @return array
   */
  function keys() {
    return array("id", "titel", "anrede", "vorname", "nachname", "co", "strasse", "plz", "ort", "postfach", "land", "kundennummer", "vertragstyp");
  }

  function sqlFinalSelect() {
    return "
# ****************#
#   FINAL SELECT  #
# ****************#

#Campaign Id for Kundennummer
SET @CiviCampaignID:= (SELECT id FROM civicrm_campaign
    WHERE external_identifier='{$this->campaignExternalIdentifier}');

#Output for CSV File
#id, titel, anrede, vorname, nachname, co, strasse, plz, ort, postfach, land, kundennummer, vertragstyp 
SELECT 	w.contact_id 			AS id
		,formal_title 			AS titel     
		, v.label 				AS anrede     
        , c.first_name 			AS vorname
        , (case contact_type
				when 'Individual' then c.last_name
				when 'Organization' then c.organization_name
				when 'Household' then c.household_name
			end)  				AS nachname
		, NULL 					AS co
        , address.street_address AS strasse
        , address.postal_code 	AS plz
		, address.city 			AS ort
        , NULL 					AS postfach
        , ctry.iso_code 		AS land
        , CONCAT(LPAD(@CiviCampaignID,5,'0'),'C',LPAD(w.contact_id, 9, '0')) 
								AS kundennummer
		, membership_type		AS vertragstyp

FROM temp_welcome w
	LEFT JOIN civicrm_contact c 			ON c.id=w.contact_id
	LEFT JOIN civicrm_address address 		ON address.contact_id=c.id AND address.is_primary=1
	LEFT JOIN civicrm_value_address_statistics address_stat ON address_stat.entity_id=address.id 
	LEFT JOIN civicrm_country ctry 			ON address.country_id=ctry.id
    LEFT JOIN civicrm_option_value v 		ON v.value=c.prefix_id AND v.option_group_id=(SELECT id FROM civicrm_option_group WHERE name ='individual_prefix')
    LEFT JOIN temp_membershiptypes mt		ON mt.contact_id=w.contact_id
    "; // DO NOT REMOVE (end of SQL statements)
  }

  /**
   * The actual array of queries
   */
  function sql() {
    // Start of sql statements
    return "
# *******#
#   OUT  #
# *******#

#Create basis table of contacts with first reduction:
#OUT: deceased, deleted, do not mail, not AT, empty address, Retourenzähler >=2
DROP TABLE IF EXISTS temp_welcome;
CREATE TEMPORARY TABLE IF NOT EXISTS temp_welcome AS 
	(
	SELECT DISTINCT c.id AS contact_id
        , 0 AS keep_contact
	FROM civicrm_contact c
		LEFT JOIN civicrm_address address 		ON address.contact_id=c.id AND is_primary=1 #NUR PRIMARY
		LEFT JOIN civicrm_value_address_statistics address_stat ON address_stat.entity_id=address.id 
		LEFT JOIN civicrm_country ctry 			ON address.country_id=ctry.id
	WHERE 
			#nicht verstorben und nicht gelöscht
			c.is_deceased=0 AND c.is_deleted=0  	
		
			#Post erwünscht
		AND c.do_not_mail=0 
        
			#österr. Adresse
        AND ctry.iso_code='AT' 
        
			#hat vollständige Adresse (Straße/PLZ/Stadt)
        AND address.street_address IS NOT NULL  AND address.postal_code IS NOT NULL AND address.city IS NOT NULL 
        
			#Retourenzähler kleiner 2
        AND address_stat.rts_counter <2  	 
        
        
    );

ALTER TABLE temp_welcome ADD PRIMARY KEY (contact_id);

#Table with all contacts, which should not receive any welcome mail
DROP TABLE IF EXISTS temp_welcome_delete;
CREATE TEMPORARY TABLE IF NOT EXISTS temp_welcome_delete AS 
	(
	SELECT DISTINCT contact_id
    FROM (	
			#OUT: inaktiv, VIP, Firma, Schule 
			SELECT DISTINCT et.entity_id AS contact_id
			FROM civicrm_entity_tag et 		
			LEFT JOIN civicrm_tag ct ON et.tag_id=ct.id 
			WHERE et.entity_table = 'civicrm_contact' AND ct.name IN ('inaktiv','VIP','Firma','Schule') 
			
			UNION ALL
            
			#OUT: Erblasser
			SELECT DISTINCT group_c.contact_id
			FROM civicrm_group_contact group_c 
			LEFT JOIN civicrm_group g ON g.id=group_c.group_id
			WHERE g.title='Erblasser'
			
			UNION ALL
			
            #OUT: Major Donors
			SELECT cb.contact_id
			FROM civicrm_contribution AS cb 
			WHERE receive_date >= NOW() - INTERVAL 1 YEAR
			GROUP BY cb.contact_id
			HAVING SUM(cb.total_amount)>=1000
            
            UNION ALL
            
            #OUT: Mailhistory in last 6 Months
            SELECT ac.contact_id 
			FROM civicrm_activity AS activity
			LEFT JOIN civicrm_activity_contact AS ac ON ac.activity_id=activity.id
			LEFT JOIN civicrm_campaign AS campaign ON campaign.id=activity.campaign_id
			WHERE campaign.external_identifier='AKTION-7767' AND activity_date_time >= NOW()-INTERVAL 6 MONTH
            
            
		) AS delete_multiple_contacts
    );
    
ALTER TABLE temp_welcome_delete ADD PRIMARY KEY (contact_id);

#Delete all contacts from the welcome-list which were collected in the delete-table    
DELETE
FROM temp_welcome 
WHERE keep_contact=0 AND contact_id IN (SELECT contact_id FROM temp_welcome_delete)
;

# *******#
#   IN   #
# *******#

#Table with all contacts, that have to be kept and should receive a welcome mail
#IN: Membership Current/ Paused, Sepa RCUR / FRST, join date in last 6 months
DROP TABLE IF EXISTS temp_welcome_keep;
CREATE TEMPORARY TABLE IF NOT EXISTS temp_welcome_keep AS 
	(
    SELECT DISTINCT contact_id
	FROM civicrm_membership AS m
	LEFT JOIN civicrm_membership_status AS ms ON m.status_id=ms.id
	LEFT JOIN civicrm_value_membership_payment AS mp ON mp.entity_id=m.id
	LEFT JOIN civicrm_option_value v ON v.value=mp.payment_instrument AND v.option_group_id=(SELECT id FROM civicrm_option_group WHERE name ='payment_instrument')
	WHERE 	
			#Memberships Current oder Paused
			ms.label IN ('Current','Paused') 
            
            #SEPA RCUR oder FRST
        AND v.name IN ('RCUR','FRST')  
        
			#join_date innerhalbt der letzten 6 Monate und vorgestern
        AND join_date >= (NOW() - INTERVAL 6 MONTH) AND join_date < (NOW() - INTERVAL 2 DAY)
    );
    
ALTER TABLE temp_welcome_keep ADD PRIMARY KEY (contact_id);

#Mark the contacts, which should definetly be on the welcome list
UPDATE temp_welcome 
SET  keep_contact=1
WHERE contact_id IN (SELECT contact_id FROM temp_welcome_keep);

# *******#
#   OUT  #
# *******#

#Final delete of all contacts, which are not marked to be kept
DELETE
FROM temp_welcome 
WHERE keep_contact=0;


#Add Information for membership types - Collect all membership types belonging to one contact
DROP TABLE IF EXISTS temp_membershiptypes;
CREATE TEMPORARY TABLE IF NOT EXISTS temp_membershiptypes 
	(contact_id 			INT(10) 	PRIMARY KEY
	, membership_type 		VARCHAR(10000)
    )
SELECT contact_id 
	, GROUP_CONCAT(DISTINCT mt.name ORDER BY mt.name SEPARATOR ', ') AS membership_type
FROM civicrm_membership as m
left join civicrm_membership_type as mt on mt.id=m.membership_type_id
LEFT JOIN civicrm_membership_status AS ms ON m.status_id=ms.id
LEFT JOIN civicrm_value_membership_payment AS mp ON mp.entity_id=m.id
LEFT JOIN civicrm_option_value v ON v.value=mp.payment_instrument AND v.option_group_id=(SELECT id FROM civicrm_option_group WHERE name ='payment_instrument')
WHERE ms.label IN ('Current','Paused') AND v.name IN ('RCUR','FRST')  AND join_date >= (NOW() - INTERVAL 6 MONTH) AND join_date < (NOW() - INTERVAL 2 DAY)
group by contact_id
;
    "; // DO NOT REMOVE (end of SQL statements)
  }
}

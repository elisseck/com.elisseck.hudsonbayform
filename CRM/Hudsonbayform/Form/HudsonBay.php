<?php

use CRM_Hudsonbayform_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Hudsonbayform_Form_HudsonBay extends CRM_Core_Form {

  public $_errors;
  public $_created;
  public $_updated;

  public function buildQuickForm() {

    CRM_Utils_System::setTitle('Hudson Bay Import Form');
    // add form elements
    $this->add('File', 'uploadFile', ts('Hudson Bay Data File'), 'size=30 maxlength=255', TRUE);
    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ),
    ));

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

public function preProcess() {
    if (isset($this->_submitFiles['uploadFile'])) {
      $uploadFile = $this->_submitFiles['uploadFile'];
      $row = 0;
      $this->_errors = [];
      $this->_created = [];
      $this->_updated = [];
      if (($handle = fopen($uploadFile['tmp_name'], "r")) !== FALSE) {
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
          $row++;
/*
0: HBCID
1: First Name
2: Last Name
3: Street Address 1
4: Street Address 2
5: City
6: State
7: Zip
8: Country
9: Donation Amount
10: Date Received
11: Fund
12: Approach
13: Campaign
14: Letter
15: Gift Type (Check, Cash, Credit Card)
16: Check Number
17: Check Date
18: Email
19: Organization Name
*/
          //Check for header row and skip it
          if ($data[0] != 'HBCID') {
          //Check for existing contact by external ID
          try {
            $checkId = civicrm_api3('Contact', 'get', [
              'sequential' => 1,
              'custom_20' => $data[0],
            ]);
          }
          catch (CiviCRM_API3_Exception $e) {
                $error = $e->getMessage();
                $this->_errors[] = $error;
                CRM_Core_Error::debug_log_message(ts('API Error %1', array(
                'domain' => 'com.elisseck.hudsonbayform',
                1 => $error,
                )));
              }
          if ($checkId['count'] == 0) {
            //If we didn't find an external ID match, check name + address
            $checkNameAddr = civicrm_api3('Contact', 'get', [
              'sequential' => 1,
              'first_name' => $data[1],
              'last_name' => $data[2],
              'street_address' => $data[3],
              'city' => $data[5],
            ]);
            //If we didn't find a match still, create the contact
            if ($checkNameAddr['count'] == 0) {
              try {
                //if it's an organization, create org params
                if ($data[20]) {
                  $params = [
                    'contact_type' => "Organization",
                    'organization_name' => $data[19],
                    'custom_20' => $data[0],
                  ];
                }
                else {
                  //otherwise create individual params
                  $params = [
                    'contact_type' => "Individual",
                    'first_name' => $data[1],
                    'last_name' => $data[2],
                    'custom_20' => $data[0],
                  ];
                }
                $newContact = civicrm_api3('Contact', 'create', $params);
              }
              catch (CiviCRM_API3_Exception $e) {
                $error = $e->getMessage();
                $this->_errors[] = $error;
                CRM_Core_Error::debug_log_message(ts('API Error %1', array(
                'domain' => 'com.elisseck.dmpform',
                1 => $error,
                )));
              }
              $foundContact = $newContact['id'];
              //if ($foundContact) {
              //We created a contact, let's log it
              $this->_created[] = $newContact['id'];
                try {
                  if ($data[8] == 'United States') {
                    $data[8] = 1228;
                  }
                  $newAddress = civicrm_api3('Address', 'create', [
                    'contact_id' => $foundContact,
                    'location_type_id' => "Main",
                    'street_address' => $data[3],
                    'supplemental_address_1' => $data[4],
                    'city' => $data[5],
                    'state_province_id' => $data[6],
                    'postal_code' => $data[7],
                    'country_id' => $data[8],
                  ]);
                }
                catch (CiviCRM_API3_Exception $e) {
                  $error = $e->getMessage();
                  $this->_errors[] = $error;
                  CRM_Core_Error::debug_log_message(ts('API Error %1', array(
                  'domain' => 'com.elisseck.hudsonbayform',
                  1 => $error,
                  )));
                }
                try {
                  $newEmail = civicrm_api3('Email', 'create', [
                    'contact_id' => $foundContact,
                    'location_type_id' => "Main",
                    'email' => $data[18],
                  ]);
                }
                catch (CiviCRM_API3_Exception $e) {
                  $error = $e->getMessage();
                  $this->_errors[] = $error;
                  CRM_Core_Error::debug_log_message(ts('API Error %1', array(
                  'domain' => 'com.elisseck.dmpform',
                  1 => $error,
                  )));
                }
              //}
            }
            else {
              $foundContact = $checkNameAddr['values'][0]['contact_id'];
              //we're updating a contact, let's log it
              $this->_updated[] = $foundContact;
            }
          }
          else {
            $foundContact = $checkId['values'][0]['contact_id'];
            //we're updating a contact, let's log it
            $this->_updated[] = $foundContact;
          }
          //Sanity check for the contact, then let's create the contact and add the contribution
          if ($foundContact) {
          try {
            if ($data[16] == 'CK') {
              $data[16] = 'Check';
            }
            $today = date("m/d/Y");
            $contribution = civicrm_api3('Contribution', 'create', [
              'financial_type_id' => "Donation",
              'source' => "Hudson Bay Import",
              'receive_date' => $data[10],
              'total_amount' => $data[9],
              'contact_id' => $foundContact,
              'payment_instrument_id' => $data[15],
              'check_number' => $data[16],
              'custom_11' => $data[11],
              'custom_23' => $data[12],
              "custom_24" => $data[13],
              "custom_25" => $data[14],
              "custom_35" => $today,
              "custom_36" => 0,
              "custom_61" => 1,
            ]);
          }
          catch (CiviCRM_API3_Exception $e) {
            $error = $e->getMessage();
            $this->_errors[$row] = $error;
            CRM_Core_Error::debug_log_message(ts('API Error %1', array(
            'domain' => 'com.elisseck.hudsonbayform',
            1 => $error,
            )));
          }
          }
        }
      }
      fclose($handle);
      }
    }
  }

  public function postProcess() {
    CRM_Core_Session::setStatus(E::ts('Success. "%1" Contacts created. "%2" Contacts updated. "%3" Errors.', array(
      1 => count($this->_created),
      2 => count($this->_updated),
      3 => count($this->_errors),
    )));
    parent::postProcess();
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}

<?php
/**
 *
 * SugarCRM Community Edition is a customer relationship management program developed by
 * SugarCRM, Inc. Copyright (C) 2004-2013 SugarCRM Inc.
 *
 * SuiteCRM is an extension to SugarCRM Community Edition developed by SalesAgility Ltd.
 * Copyright (C) 2011 - 2017 SalesAgility Ltd.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License version 3 as published by the
 * Free Software Foundation with the addition of the following permission added
 * to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED WORK
 * IN WHICH THE COPYRIGHT IS OWNED BY SUGARCRM, SUGARCRM DISCLAIMS THE WARRANTY
 * OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU Affero General Public License for more
 * details.
 *
 * You should have received a copy of the GNU Affero General Public License along with
 * this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 *
 * You can contact SugarCRM, Inc. headquarters at 10050 North Wolfe Road,
 * SW2-130, Cupertino, CA 95014, USA. or at email address contact@sugarcrm.com.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "Powered by
 * SugarCRM" logo and "Supercharged by SuiteCRM" logo. If the display of the logos is not
 * reasonably feasible for technical reasons, the Appropriate Legal Notices must
 * display the words "Powered by SugarCRM" and "Supercharged by SuiteCRM".
 */

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

include_once 'include/Exceptions/SugarControllerException.php';

class EmailsController extends SugarController
{
    /**
     * @var Email $bean ;
     */
    public $bean;

    /**
     * @see EmailsController::composeBean()
     */
    const COMPOSE_BEAN_MODE_UNDEFINED = 0;

    /**
     * @see EmailsController::composeBean()
     */
    const COMPOSE_BEAN_MODE_REPLY_TO = 1;

    /**
     * @see EmailsController::composeBean()
     */
    const COMPOSE_BEAN_MODE_REPLY_TO_ALL = 2;

    /**
     * @see EmailsController::composeBean()
     */
    const COMPOSE_BEAN_MODE_FORWARD = 3;

    /**
     * @see EmailsController::composeBean()
     */
    const COMPOSE_BEAN_WITH_PDF_TEMPLATE = 4;

    protected static $doNotImportFields = array(
        'action',
        'type',
        'send',
        'record',
        'from_addr_name',
        'reply_to_addr',
        'to_addrs_names',
        'cc_addrs_names',
        'bcc_addrs_names',
        'imap_keywords',
        'raw_source',
        'description',
        'description_html',
        'date_sent',
        'message_id',
        'name',
        'status',
        'reply_to_status',
        'mailbox_id',
        'created_by_link',
        'modified_user_link',
        'assigned_user_link',
        'assigned_user_link',
        'uid',
        'msgno',
        'folder',
        'folder_type',
        'inbound_email_record',
        'is_imported',
        'has_attachment',
        'id',
    );

    /**
     * @see EmailsViewList
     */
    public function action_index()
    {
        $this->view = 'list';
    }

    /**
     * @see EmailsViewDetaildraft
     */
    public function action_DetailDraftView()
    {
        $this->view = 'detaildraft';
    }

    /**
     * @see EmailsViewCompose
     */
    public function action_ComposeView()
    {
        $this->view = 'compose';
        // For viewing the Compose as modal from other modules we need to load the Emails language strings
        if (isset($_REQUEST['in_popup']) && $_REQUEST['in_popup']) {
            if (!is_file('cache/jsLanguage/Emails/' . $GLOBALS['current_language'] . '.js')) {
                require_once ('include/language/jsLanguage.php');
                jsLanguage::createModuleStringsCache('Emails', $GLOBALS['current_language']);
            }
            echo '<script src="cache/jsLanguage/Emails/'. $GLOBALS['current_language'] . '.js"></script>';
        }
        if (isset($_REQUEST['ids']) && isset($_REQUEST['targetModule'])) {
            $toAddressIds = explode(',', rtrim($_REQUEST['ids'], ','));
            foreach ($toAddressIds as $id) {
                $destinataryBean = BeanFactory::getBean($_REQUEST['targetModule'], $id);
                if ($destinataryBean) {
                    $idLine = '<input type="hidden" class="email-compose-view-to-list" ';
                    $idLine .= 'data-record-module="' . $_REQUEST['targetModule'] . '" ';
                    $idLine .= 'data-record-id="' . $id . '" ';
                    $idLine .= 'data-record-name="' . $destinataryBean->name . '" ';
                    $idLine .= 'data-record-email="' . $destinataryBean->email1 . '">';
                    echo $idLine;
                }
            }
        }
        if (isset($_REQUEST['relatedModule']) && isset($_REQUEST['relatedId'])) {
            $relateBean = BeanFactory::getBean($_REQUEST['relatedModule'], $_REQUEST['relatedId']);
            $relateLine = '<input type="hidden" class="email-relate-target" ';
            $relateLine .= 'data-relate-module="' . $_REQUEST['relatedModule'] . '" ';
            $relateLine .= 'data-relate-id="' . $_REQUEST['relatedId'] . '" ';
            $relateLine .= 'data-relate-name="' . $relateBean->name . '">';
            echo $relateLine;
        }
    }

    /**
     * @see EmailsViewSendemail
     */
    public function action_send()
    {
        global $current_user;
        global $app_strings;

        $request = $_REQUEST;

        $this->bean = $this->bean->populateBeanFromRequest($this->bean, $request);
        $inboundEmailAccount = new InboundEmail();
        $inboundEmailAccount->retrieve($_REQUEST['inbound_email_id']);

        if ($this->userIsAllowedToSendEmail($current_user, $inboundEmailAccount, $this->bean)) {
            $this->bean->save();

            $this->bean->handleMultipleFileAttachments();

            // parse and replace bean variables
            $this->bean = $this->replaceEmailVariables($this->bean, $request);

            if ($this->bean->send()) {
                $this->bean->status = 'sent';
                $this->bean->save();
            } else {
                // Don't save status if the email is a draft.
                // We need to ensure that drafts will still show
                // in the list view
                if ($this->bean->status !== 'draft') {
                    $this->bean->save();
                }
                $this->bean->status = 'send_error';
            }

            $this->view = 'sendemail';
        } else {
            $GLOBALS['log']->security(
                'User ' . $current_user->name .
                ' attempted to send an email using incorrect email account settings in' .
                ' which they do not have access to.'
            );

            $this->view = 'ajax';
            $response['errors'] = [
                'type' => get_class($this->bean),
                'id' => $this->bean->id,
                'title' => $app_strings['LBL_EMAIL_ERROR_SENDING']
            ];
            echo json_encode($response);
        }
    }

    /**
     * Parse and replace bean variables
     * but first validate request,
     * see log to check validation problems
     *
     * return Email bean
     *
     * @param Email $email
     * @param array $request
     * @return Email
     */
    protected function replaceEmailVariables(Email $email, $request)
    {
        // request validation before replace bean variables

        if ($this->isValidRequestForReplaceEmailVariables($request)) {
            $macro_nv = array();

            $focusName = $request['parent_type'];
            $focus = BeanFactory::getBean($focusName, $request['parent_id']);
            if ($email->module_dir == 'Accounts') {
                $focusName = 'Accounts';
            }

            /**
             * @var EmailTemplate $emailTemplate
             */
            $emailTemplate = BeanFactory::getBean(
                'EmailTemplates',
                isset($request['emails_email_templates_idb']) ?
                    $request['emails_email_templates_idb'] :
                    null
            );
            $templateData = $emailTemplate->parse_email_template(
                array(
                    'subject' => $email->name,
                    'body_html' => $email->description_html,
                    'body' => $email->description,
                ),
                $focusName,
                $focus,
                $macro_nv
            );

            $email->name = $templateData['subject'];
            $email->description_html = $templateData['body_html'];
            $email->description = $templateData['body'];
        } else {
            $this->log('Email variables is not replaced because an invalid request.');
        }


        return $email;
    }

    /**
     * Request validation before replace bean variables,
     * see log to check validation problems
     *
     * @param array $request
     * @return bool
     */
    protected function isValidRequestForReplaceEmailVariables($request)
    {
        $isValidRequestForReplaceEmailVariables = true;

        if (!is_array($request)) {

            // request should be an array like standard $_REQUEST

            $isValidRequestForReplaceEmailVariables = false;
            $this->log('Incorrect request format');
        }


        if (!isset($request['parent_type']) || !$request['parent_type']) {

            // there is no any selected option in 'Related To' field
            // so impossible to replace variables to selected bean data

            $isValidRequestForReplaceEmailVariables = false;
            $this->log('There isn\'t any selected BEAN-TYPE option in \'Related To\' dropdown');
        }


        if (!isset($request['parent_id']) || !$request['parent_id']) {

            // there is no any selected bean in 'Related To' field
            // so impossible to replace variables to selected bean data

            $isValidRequestForReplaceEmailVariables = false;
            $this->log('There isn\'t any selected BEAN-ELEMENT in \'Related To\' field');
        }


        return $isValidRequestForReplaceEmailVariables;
    }

    /**
     * Add a message to log
     *
     * @param string $msg
     * @param string $level
     */
    private function log($msg, $level = 'info')
    {
        $GLOBALS['log']->$level($msg);
    }

    /**
     * @see EmailsViewCompose
     */
    public function action_SaveDraft()
    {
        $this->bean = $this->bean->populateBeanFromRequest($this->bean, $_REQUEST);
        $this->bean->status = 'draft';
        $this->bean->save();
        $this->bean->handleMultipleFileAttachments();
        $this->view = 'savedraftemail';
    }

    /**
     * @see EmailsViewCompose
     */
    public function action_DeleteDraft()
    {
        $this->bean->deleted = '1';
        $this->bean->status = 'draft';
        $this->bean->save();
        $this->view = 'deletedraftemail';
    }


    /**
     * @see EmailsViewPopup
     */
    public function action_Popup()
    {
        $this->view = 'popup';
    }

    /**
     * Gets the values of the "from" field
     * includes the signatures for each account
     */
    public function action_GetFromFields()
    {
        global $current_user;
        $email = new Email();
        $email->email2init();
        $ie = new InboundEmail();
        $ie->email = $email;
        $accounts = $ieAccountsFull = $ie->retrieveAllByGroupIdWithGroupAccounts($current_user->id);
        $accountSignatures = $current_user->getPreference('account_signatures', 'Emails');
        $showFolders = unserialize(base64_decode($current_user->getPreference('showFolders', 'Emails')));
        if ($accountSignatures != null) {
            $emailSignatures = unserialize(base64_decode($accountSignatures));
        } else {
            $GLOBALS['log']->warn('User ' . $current_user->name . ' does not have a signature');
        }

        $defaultEmailSignature = $current_user->getDefaultSignature();
        if (empty($defaultEmailSignature)) {
            $defaultEmailSignature = array(
                'html' => '<br>',
                'plain' => '\r\n',
            );
            $defaultEmailSignature['no_default_available'] = true;
        } else {
            $defaultEmailSignature['no_default_available'] = false;
        }

        $prependSignature = $current_user->getPreference('signature_prepend');

        $data = array();
        foreach ($accounts as $inboundEmailId => $inboundEmail) {
            if (in_array($inboundEmail->id, $showFolders)) {
                $storedOptions = unserialize(base64_decode($inboundEmail->stored_options));
                $isGroupEmailAccount = $inboundEmail->isGroupEmailAccount();
                $isPersonalEmailAccount = $inboundEmail->isPersonalEmailAccount();

                $oe = new OutboundEmail();
                $oe->retrieve($storedOptions['outbound_email']);
                
                $dataAddress = array(
                    'type' => $inboundEmail->module_name,
                    'id' => $inboundEmail->id,
                    'attributes' => array(
                        'reply_to' => $storedOptions['reply_to_addr'],
                        'name' => $storedOptions['from_name'],
                        'from' => $storedOptions['from_addr'],
                    ),
                    'prepend' => $prependSignature,
                    'isPersonalEmailAccount' => $isPersonalEmailAccount,
                    'isGroupEmailAccount' => $isGroupEmailAccount,
                    'outboundEmail' => array(
                        'id' => $oe->id,
                        'name' => $oe->name,
                    ),
                );

                // Include signature
                if (isset($emailSignatures[$inboundEmail->id]) && !empty($emailSignatures[$inboundEmail->id])) {
                    $emailSignatureId = $emailSignatures[$inboundEmail->id];
                } else {
                    $emailSignatureId = '';
                }

                $signature = $current_user->getSignature($emailSignatureId);
                if (!$signature) {
                    if ($defaultEmailSignature['no_default_available'] === true) {
                        $dataAddress['emailSignatures'] = $defaultEmailSignature;
                    } else {
                        $dataAddress['emailSignatures'] = array(
                            'html' => utf8_encode(html_entity_decode($defaultEmailSignature['signature_html'])),
                            'plain' => $defaultEmailSignature['signature'],
                        );
                    }
                } else {
                    $dataAddress['emailSignatures'] = array(
                        'html' => utf8_encode(html_entity_decode($signature['signature_html'])),
                        'plain' => $signature['signature'],
                    );
                }

                $data[] = $dataAddress;
            }
        }

        $oe = new OutboundEmail();
        if ($oe->isAllowUserAccessToSystemDefaultOutbound()) {
            $system = $oe->getSystemMailerSettings();
            $data[] = array(
                'type' => 'system',
                'id' => $system->id,
                'attributes' => array(
                    'reply_to' => $system->smtp_from_addr,
                    'from' => $system->smtp_from_addr,
                    'name' => $system->smtp_from_name,
                    'oe' => $system->mail_smtpuser,
                ),
                'prepend' => false,
                'isPersonalEmailAccount' => false,
                'isGroupEmailAccount' => true,
                'outboundEmail' => array(
                    'id' => $system->id,
                    'name' => $system->name,
                ),
                'emailSignatures' => $defaultEmailSignature,
            );
        }

        $dataEncoded = json_encode(array('data' => $data), JSON_UNESCAPED_UNICODE);
        echo utf8_decode($dataEncoded);
        $this->view = 'ajax';
    }

    /**
     * Returns attachment data to ajax call
     */
    public function action_GetDraftAttachmentData()
    {
        $data['attachments'] = array();

        if (!empty($_REQUEST['id'])) {
            $bean = BeanFactory::getBean('Emails', $_REQUEST['id']);
            $data['draft'] = $bean->status == 'draft' ? 1 : 0;
            $attachmentBeans = BeanFactory::getBean('Notes')
                ->get_full_list('', "parent_id = '" . $_REQUEST['id'] . "'");
            foreach ($attachmentBeans as $attachmentBean) {
                $data['attachments'][] = array(
                    'id' => $attachmentBean->id,
                    'name' => $attachmentBean->name,
                    'file_mime_type' => $attachmentBean->file_mime_type,
                    'filename' => $attachmentBean->filename,
                    'parent_type' => $attachmentBean->parent_type,
                    'parent_id' => $attachmentBean->parent_id,
                    'description' => $attachmentBean->description,
                );
            }
        }

        $dataEncoded = json_encode(array('data' => $data), JSON_UNESCAPED_UNICODE);
        echo utf8_decode($dataEncoded);
        $this->view = 'ajax';
    }

    public function action_CheckEmail()
    {
        $inboundEmail = new InboundEmail();
        $inboundEmail->syncEmail();

        echo json_encode(array('response' => array()));
        $this->view = 'ajax';
    }

    /**
     * Used to list folders in the list view
     */
    public function action_GetFolders()
    {
        require_once 'include/SugarFolders/SugarFolders.php';
        global $current_user, $mod_strings;
        $email = new Email();
        $email->email2init();
        $ie = new InboundEmail();
        $ie->email = $email;
        $GLOBALS['log']->debug('********** EMAIL 2.0 - Asynchronous - at: refreshSugarFolders');
        $rootNode = new ExtNode('', '');
        $folderOpenState = $current_user->getPreference('folderOpenState', 'Emails');
        $folderOpenState = empty($folderOpenState) ? '' : $folderOpenState;

        try {
            $ret = $email->et->folder->getUserFolders($rootNode, sugar_unserialize($folderOpenState), $current_user,
                true);
            $out = json_encode(array('response' => $ret));
        } catch (SugarFolderEmptyException $e) {
            $GLOBALS['log']->warn($e->getMessage());
            $out = json_encode(array('errors' => array($mod_strings['LBL_ERROR_NO_FOLDERS'])));
        }

        echo $out;
        $this->view = 'ajax';
    }


    /**
     * @see EmailsViewDetailnonimported
     */
    public function action_DisplayDetailView()
    {
        $db = DBManagerFactory::getInstance();
        $emails = BeanFactory::getBean("Emails");
        
        $inboundEmailRecordIdQuoted = $db->quote($_REQUEST['inbound_email_record']);
        $uidQuoted = $db->quote($_REQUEST['uid']);
        
        $result = $emails->get_full_list('', "mailbox_id = '" . $inboundEmailRecordIdQuoted . "' AND uid = '" . $uidQuoted . "'");

        if (empty($result)) {
            $this->view = 'detailnonimported';
        } else {
            header('location:index.php?module=Emails&action=DetailView&record=' . $result[0]->id);
        }
    }

    /**
     * @see EmailsViewDetailnonimported
     */
    public function action_ImportAndShowDetailView()
    {
        $db = DBManagerFactory::getInstance();
        if (isset($_REQUEST['inbound_email_record']) && !empty($_REQUEST['inbound_email_record'])) {
            $inboundEmail = new InboundEmail();
            $inboundEmail->retrieve($db->quote($_REQUEST['inbound_email_record']), true, true);
            $inboundEmail->connectMailserver();
            $importedEmailId = $inboundEmail->returnImportedEmail($_REQUEST['msgno'], $_REQUEST['uid']);


            // Set the fields which have been posted in the request
            $this->bean = $this->setAfterImport($importedEmailId, $_REQUEST);

            if ($importedEmailId !== false) {
                header('location:index.php?module=Emails&action=DetailView&record=' . $importedEmailId);
            }
        } else {
            // When something fail redirect user to index
            header('location:index.php?module=Emails&action=index');
        }
    }

    /**
     * @see EmailsViewImport
     */
    public function action_ImportView()
    {
        $this->view = 'import';
    }

    public function action_GetCurrentUserID()
    {
        global $current_user;
        echo json_encode(array("response" => $current_user->id));
        $this->view = 'ajax';
    }

    public function action_ImportFromListView()
    {
        $db = DBManagerFactory::getInstance();

        if (isset($_REQUEST['inbound_email_record']) && !empty($_REQUEST['inbound_email_record'])) {
            $inboundEmail = BeanFactory::getBean('InboundEmail', $db->quote($_REQUEST['inbound_email_record']));
            if (isset($_REQUEST['folder']) && !empty($_REQUEST['folder'])) {
                $inboundEmail->mailbox = $_REQUEST['folder'];
            }
            $inboundEmail->connectMailserver();

            if (isset($_REQUEST['all']) && $_REQUEST['all'] === 'true') {
                // import all in folder
                $importedEmailsId = $inboundEmail->importAllFromFolder();
                foreach ($importedEmailsId as $importedEmailId) {
                    $this->bean = $this->setAfterImport($importedEmailId, $_REQUEST);
                }
            } else {
                foreach ($_REQUEST['uid'] as $uid) {
                    $importedEmailId = $inboundEmail->returnImportedEmail($_REQUEST['msgno'], $uid);
                    $this->bean = $this->setAfterImport($importedEmailId, $_REQUEST);
                }
            }
        } else {
            $GLOBALS['log']->fatal('EmailsController::action_ImportFromListView() missing inbound_email_record');
        }

        header('location:index.php?module=Emails&action=index');
    }

    public function action_ReplyTo()
    {
        $this->composeBean($_REQUEST, self::COMPOSE_BEAN_MODE_REPLY_TO);
        $this->view = 'compose';
    }

    public function action_ReplyToAll()
    {
        $this->composeBean($_REQUEST, self::COMPOSE_BEAN_MODE_REPLY_TO_ALL);
        $this->view = 'compose';
    }

    public function action_Forward()
    {
        $this->composeBean($_REQUEST, self::COMPOSE_BEAN_MODE_FORWARD);
        $this->view = 'compose';
    }

    /**
     * Fills compose view body with the output from PDF Template
     * @see sendEmail::send_email()
     */
    public function action_ComposeViewWithPdfTemplate()
    {
        $this->composeBean($_REQUEST, self::COMPOSE_BEAN_WITH_PDF_TEMPLATE);
        $this->view = 'compose';
    }

    public function action_SendDraft()
    {
        $this->view = 'ajax';
        echo json_encode(array());
    }


    /**
     * @throws SugarControllerException
     */
    public function action_MarkEmails()
    {
        $this->markEmails($_REQUEST);
        echo json_encode(array('response' => true));
        $this->view = 'ajax';
    }

    /**
     * @param array $request
     * @throws SugarControllerException
     */
    public function markEmails($request)
    {
        // validate the request

        if (!isset($request['inbound_email_record']) || !$request['inbound_email_record']) {
            throw new SugarControllerException('No Inbound Email record in request');
        }

        if (!isset($request['folder']) || !$request['folder']) {
            throw new SugarControllerException('No Inbound Email folder in request');
        }

        // connect to requested inbound email server
        // and select the folder

        $ie = $this->getInboundEmail($request['inbound_email_record']);
        $ie->mailbox = $request['folder'];
        $ie->connectMailserver();

        // get requested UIDs and flag type

        $UIDs = $this->getRequestedUIDs($request);
        $type = $this->getRequestedFlagType($request);

        // mark emails
        $ie->markEmails($UIDs, $type);
    }

    /**
     * @param array $request
     * @param int $mode
     * @throws InvalidArgumentException
     * @see EmailsController::COMPOSE_BEAN_MODE_UNDEFINED
     * @see EmailsController::COMPOSE_BEAN_MODE_REPLY_TO
     * @see EmailsController::COMPOSE_BEAN_MODE_REPLY_TO_ALL
     * @see EmailsController::COMPOSE_BEAN_MODE_FORWARD
     */
    public function composeBean($request, $mode = self::COMPOSE_BEAN_MODE_UNDEFINED)
    {
        if ($mode === self::COMPOSE_BEAN_MODE_UNDEFINED) {
            throw new InvalidArgumentException('EmailController::composeBean $mode argument is COMPOSE_BEAN_MODE_UNDEFINED');
        }

        $db = DBManagerFactory::getInstance();
        global $mod_strings;

                
        global $current_user;
        $email = new Email();
        $email->email2init();
        $ie = new InboundEmail();
        $ie->email = $email;
        $accounts = $ieAccountsFull = $ie->retrieveAllByGroupIdWithGroupAccounts($current_user->id);
        if (!$accounts) {
            $url = 'index.php?module=Users&action=EditView&record=' . $current_user->id . "&showEmailSettingsPopup=1";
            SugarApplication::appendErrorMessage(
                    "You don't have any valid email account settings yet. <a href=\"$url\">Click here to set your email accounts.</a>");
        }
        
        
        if (isset($request['record']) && !empty($request['record'])) {
            $this->bean->retrieve($request['record']);
        } else {
            $inboundEmail = BeanFactory::getBean('InboundEmail', $db->quote($request['inbound_email_record']));
            $inboundEmail->connectMailserver();
            $importedEmailId = $inboundEmail->returnImportedEmail($request['msgno'], $request['uid']);
            $this->bean->retrieve($importedEmailId);
        }

        $_REQUEST['return_module'] = 'Emails';
        $_REQUEST['return_Action'] = 'index';

        if ($mode === self::COMPOSE_BEAN_MODE_REPLY_TO || $mode === self::COMPOSE_BEAN_MODE_REPLY_TO_ALL) {
            // Move email addresses from the "from" field to the "to" field
            $this->bean->to_addrs = $this->bean->from_addr;
            isValidEmailAddress($this->bean->to_addrs);
            $this->bean->to_addrs_names = $this->bean->from_addr_name;
        } else {
            if ($mode === self::COMPOSE_BEAN_MODE_FORWARD) {
                $this->bean->to_addrs = '';
                $this->bean->to_addrs_names = '';
            } else {
                if ($mode === self::COMPOSE_BEAN_WITH_PDF_TEMPLATE) {
                    // Get Related To Field
                    // Populate to
                }
            }
        }

        if ($mode !== self::COMPOSE_BEAN_MODE_REPLY_TO_ALL) {
            $this->bean->cc_addrs_arr = array();
            $this->bean->cc_addrs_names = '';
            $this->bean->cc_addrs = '';
            $this->bean->cc_addrs_ids = '';
            $this->bean->cc_addrs_emails = '';
        }

        if ($mode === self::COMPOSE_BEAN_MODE_REPLY_TO || $mode === self::COMPOSE_BEAN_MODE_REPLY_TO_ALL) {
            // Add Re to subject
            $this->bean->name = $mod_strings['LBL_RE'] . $this->bean->name;
        } else {
            if ($mode === self::COMPOSE_BEAN_MODE_FORWARD) {
                // Add FW to subject
                $this->bean->name = $mod_strings['LBL_FW'] . $this->bean->name;
            }
        }

        if (empty($this->bean->name)) {
            $this->bean->name = $mod_strings['LBL_NO_SUBJECT'] . $this->bean->name;
        }

        // Move body into original message
        if (!empty($this->bean->description_html)) {
            $this->bean->description = '<br>' . $mod_strings['LBL_ORIGINAL_MESSAGE_SEPERATOR'] . '<br>' .
                $this->bean->description_html;
        } else {
            if (!empty($this->bean->description)) {
                $this->bean->description = PHP_EOL . $mod_strings['LBL_ORIGINAL_MESSAGE_SEPERATOR'] . PHP_EOL .
                    $this->bean->description;
            }
        }

        $this->bean->description_html = '';
    }


    /**
     * @param $request
     * @return null|string
     */
    private function getRequestedUIDs($request)
    {
        $ret = $this->getRequestedArgument($request, 'uid');
        if (is_array($ret)) {
            $ret = implode(',', $ret);
        }

        return $ret;
    }

    /**
     * @param array $request
     * @return null|mixed
     */
    private function getRequestedFlagType($request)
    {
        $ret = $this->getRequestedArgument($request, 'type');

        return $ret;
    }

    /**
     * @param array $request
     * @param string $key
     * @return null|mixed
     */
    private function getRequestedArgument($request, $key)
    {
        if (!isset($request[$key])) {
            $GLOBALS['log']->error("Requested key is not set: ");

            return null;
        }

        return $request[$key];
    }

    /**
     * return an Inbound Email by requested record
     *
     * @param string $record
     * @return InboundEmail
     * @throws SugarControllerException
     */
    private function getInboundEmail($record)
    {
        $db = DBManagerFactory::getInstance();
        $ie = BeanFactory::getBean('InboundEmail', $db->quote($record));
        if (!$ie) {
            throw new SugarControllerException("BeanFactory can't resolve an InboundEmail record: $record");
        }

        return $ie;
    }

    /**
     * @param array $request
     * @return bool|Email
     * @see Email::id
     * @see EmailsController::action_ImportAndShowDetailView()
     * @see EmailsController::action_ImportView()
     */
    protected function setAfterImport($importedEmailId, $request)
    {
        $emails = BeanFactory::getBean("Emails", $importedEmailId);
        foreach ($request as $requestKey => $requestValue) {
            if (strpos($requestKey, 'SET_AFTER_IMPORT_') !== false) {
                $field = str_replace('SET_AFTER_IMPORT_', '', $requestKey);
                if (in_array($field, self::$doNotImportFields)) {
                    continue;
                }

                $emails->{$field} = $requestValue;
            }
        }

        $emails->save();

        return $emails;
    }

    /**
     * @param User $requestedUser
     * @param InboundEmail $requestedInboundEmail
     * @param Email $requestedEmail
     * @return bool false if user doesn't have access
     */
    protected function userIsAllowedToSendEmail($requestedUser, $requestedInboundEmail, $requestedEmail)
    {
        // Check that user is allowed to use inbound email account
        $hasAccessToInboundEmailAccount = false;
        $usersInboundEmailAccounts = $requestedInboundEmail->retrieveAllByGroupIdWithGroupAccounts($requestedUser->id);
        foreach ($usersInboundEmailAccounts as $inboundEmailId => $userInboundEmail) {
            if ($userInboundEmail->id === $requestedInboundEmail->id) {
                $hasAccessToInboundEmailAccount = true;
                break;
            }
        }

        $inboundEmailStoredOptions = $requestedInboundEmail->getStoredOptions();

        // if group email account, check that user is allowed to use group email account
        if ($requestedInboundEmail->isGroupEmailAccount()) {
            if ($inboundEmailStoredOptions['allow_outbound_group_usage'] === true) {
                $hasAccessToInboundEmailAccount = true;
            } else {
                $hasAccessToInboundEmailAccount = false;
            }
        }

        // Check that the from address is the same as the inbound email account
        $isFromAddressTheSame = false;
        if ($inboundEmailStoredOptions['from_addr'] === $requestedEmail->from_addr) {
            $isFromAddressTheSame = true;
        }

        // Check if user is using the system account, as the email address for the system account, will have different
        // settings. If there is not an outbound email id in the stored options then we should try
        // and use the system account, provided that the user is allowed to use to the system account.
        $outboundEmailAccount = new OutboundEmail();
        if (empty($inboundEmailStoredOptions['outbound_email'])) {
            $outboundEmailAccount->getSystemMailerSettings();
        } else {
            $outboundEmailAccount->retrieve($inboundEmailStoredOptions['outbound_email']);
        }

        $isAllowedToUseOutboundEmail = false;
        if ($outboundEmailAccount->type === 'system') {
            if ($outboundEmailAccount->isAllowUserAccessToSystemDefaultOutbound()) {
                $isAllowedToUseOutboundEmail = true;
            }

            // When there are not any authentication details for the system account, allow the user to use the system
            // email account.
            if ($outboundEmailAccount->mail_smtpauth_req == 0) {
                $isAllowedToUseOutboundEmail = true;
            }

            $admin = new Administration();
            $admin->retrieveSettings();
            $adminNotifyFromAddress = $admin->settings['notify_fromaddress'];
            if ($adminNotifyFromAddress === $requestedEmail->from_addr) {
                $isFromAddressTheSame = true;
            }
        } elseif ($outboundEmailAccount->type === 'user') {
            $isAllowedToUseOutboundEmail = true;
        }

        // The inbound email account is an empty object, we assume the user has access
        if (empty($requestedInboundEmail->id)) {
            $hasAccessToInboundEmailAccount = true;
            $isFromAddressTheSame = true;
        }

        $error = false;
        if ($hasAccessToInboundEmailAccount !== true) {
            $error = 'Email Error: Not authorized to use Inbound Account "' . $requestedInboundEmail->name . '"';
        }
        if ($isFromAddressTheSame !== true) {
            $error = 'Email Error: Requested From address mismatch "'
                . $requestedInboundEmail->name . '" / "' . $requestedEmail->from_addr . '"';
        }
        if ($isAllowedToUseOutboundEmail !== true) {
            $error = 'Email Error: Not authorized to use Outbound Account "' . $outboundEmailAccount->name . '"';
        }
        if ($error !== false) {
            $GLOBALS['log']->security($error);
            return false;
        }
        return true;
    }
}

<?php
/**
 * @copyright 2009-2017 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GNU GPL v2
 */

namespace Vanilla\Models;

//use Interop\Container\ContainerInterface;
use Gdn_Configuration;
use Gdn_Session;
use UserModel;
use Vanilla\AddonManager;
use Vanilla\Utility\CapitalCaseScheme;

/**
 * Class SSOModel
 */
class SSOModel {

    /** @var AddonManager */
    private $addonManager;

    /** @var Gdn_Configuration */
    private $config;

    /** @var CapitalCaseScheme */
    private $capitalCaseScheme;

//    /** @var Container */
//    private $container;


    /** @var  \Gdn_Session */
    private $session;

    /** @var UserModel */
    private $userModel;

    /**
     * SSOModel constructor.
     *
     * @param AddonManager $addonManager
     * @param Gdn_Configuration $config
//     * @param ContainerInterface $container
     * @param Gdn_Session $session
     * @param UserModel $userModel
     */
    public function __construct(
        AddonManager $addonManager,
        Gdn_Configuration $config,
//        ContainerInterface $container,
        Gdn_Session $session,
        UserModel $userModel
    ) {
        $this->addonManager = $addonManager;
        $this->capitalCaseScheme = new CapitalCaseScheme();
        $this->config = $config;
//        $this->container = $container;
        $this->session = $session;
        $this->userModel = $userModel;
    }

    /**
     * Automatically makes a link in Gdn_UserAuthentication using the email address.
     *
     * @param SSOInfo $ssoInfo
     * @return array|bool User data if found or false otherwise.
     */
    private function autoConnect(SSOInfo $ssoInfo) {
        $email = $ssoInfo->getExtraInfo('email', null);
        if (!isset($email)) {
            return false;
        }

        $userData = $this->userModel->getWhere(['Email' => $email])->firstRow(DATASET_TYPE_ARRAY);
        if ($userData !== false) {
            $this->userModel->saveAuthentication([
                'UserID' => $userData['UserID'],
                'Provider' => $ssoInfo['authenticatorID'],
                'UniqueID' => $ssoInfo['uniqueID']
            ]);
        }
        return $userData;
    }

    /**
     * Try to find a user matching the provided SSOInfo.
     * Email has priority over Name if both are allowed.
     *
     * @param SSOInfo $ssoInfo SSO provided user's information.
     * @param string $findByEmail Try to find the user by Email.
     * @param string $findByName Try to find the user by Name.
     * @return array User objects that matches the SSOInfo.
     */
    public function findMatchingUserIDs(SSOInfo $ssoInfo, $findByEmail, $findByName) {
        if (!$findByEmail && !$findByName) {
            return [];
        }

        $email = $ssoInfo->getExtraInfo('email');
        $name = $ssoInfo->getExtraInfo('name');
        if (!$email && !$name) {
            return [];
        }

        $sql = $this->userModel->SQL;

        $sql->select(['UserID'])->where(['Banned' => 0]);

        $sql->andOp()->beginWhereGroup();
        $previousCondition = false;

        if ($findByEmail && $email) {
            $previousCondition = true;
            $sql->where(['Email' => $email]);
        }

        if ($findByName && $name) {
            if ($previousCondition) {
                $sql->orOp();
            }
            $sql->where(['Name' => $name]);
        }

        $sql->endWhereGroup();

        return $this->userModel->getWhere()->resultArray();
    }

    public function sso(SSOInfo $ssoInfo) {
        // Will throw a proper exception.
        $ssoInfo->validate();

        $user = $this->userModel->getAuthentication($ssoInfo['uniqueID'], $ssoInfo['authenticatorID']);

        if (!$user) {
            // Allows registration without an email address.
            $noEmail = $this->config->get('Garden.Registration.NoEmail', false);

            // Specifies whether Emails are unique or not.
            $emailUnique = !$noEmail && $this->config->get('Garden.Registration.EmailUnique', true);

            // Allows SSO connections to link a VanillaUser to a ForeignUser.
            $allowConnect = $this->config->get('Garden.Registration.AllowConnect', true);

            // Will automatically try to link users using the provided Email address if the Provider is "Trusted".
            $autoConnect = $allowConnect && $emailUnique && $this->config->get('Garden.Registration.AutoConnect', false);

            // Let's try to find a matching user.
            if ($autoConnect) {
                $user = $this->autoConnect($ssoInfo);
            }
        }

        if ($user) {
            $this->session->start($user['UserID']);

            if ($ssoInfo['authenticatorIsTrusted']) {
                // Synchronize user's info.
                $syncInfo = $this->config->get('Garden.Registration.ConnectSynchronize', true);

                // Synchronize user's roles only on registration.
                $syncRolesOnlyRegistration = $this->config->get('Garden.SSO.SyncRolesOnRegistrationOnly', false);

                // This coupling sucks but I feel like that's the best way to accommodate the config!
                if ($syncRolesOnlyRegistration && val('connectOption', $ssoInfo) !== 'createuser') {
                    $syncRoles = false;
                } else {
                    // Synchronize user's roles.
                    $syncRoles = $this->config->get('Garden.SSO.SyncRoles', false);
                }

                if (!$this->syncUser($ssoInfo, $user, $syncInfo, $syncRoles)) {
                    throw new ServerException(
                        "User synchronization failed",
                        500,
                        [
                            'validationResults' => $this->userModel->validationResults()
                        ]
                    );
                }
            }
        }

        return $user;
    }

    /**
     * Synchronize a user using the provided data.
     *
     * @param SSOInfo $ssoInfo SSO provided user data.
     * @param array $user Current user's data.
     * @param bool $syncInfo Synchronize the user's information.
     * @param bool $syncRoles Synchronize the user's roles.
     * @return bool If the synchronisation was a success ot not.
     */
    private function syncUser(SSOInfo $ssoInfo, $user, $syncInfo, $syncRoles) {
        if (!$syncInfo && !$syncRoles) {
            return true;
        }

        $userInfo = [
            'UserID' => $user['UserID']
        ];

        if ($syncInfo) {
            $userInfo = array_merge($this->capitalCaseScheme->convertArrayKeys((array)$ssoInfo), $userInfo);

            // Don't overwrite the user photo if the user uploaded a new one.
            $photo = val('Photo', $user);
            if (!val('Photo', $userInfo) || ($photo && !isUrl($photo))) {
                unset($userInfo['Photo']);
            }
        }

        $saveRoles = $syncRoles && array_key_exists('roles', $ssoInfo);
        if ($saveRoles) {
            if (!empty($ssoInfo['roles'])) {
                $roles = \RoleModel::getByName($ssoInfo['roles']);
                $roleIDs = array_keys($roles);
            }

            // Ensure user has at least one role.
            if (empty($roleIDs)) {
                $roleIDs = $this->userModel->newUserRoleIDs();
            }

            $userInfo['RoleID'] = $roleIDs;
        }

        $userID = $this->userModel->save($userInfo, [
            'NoConfirmEmail' => true,
            'FixUnique' => true,
            'SaveRoles' => $saveRoles,
        ]);

        /*
         * TODO: Add a replacement event for AfterConnectSave.
         * It was added 8 months ago so it is safe to assume that the only usage of it is the CategoryRoles plugin.
         * https://github.com/vanilla/vanilla/commit/1d9ae17652213d888bbd07cac0f682959ca326b9
         */

        return $userID !== false;
    }
}

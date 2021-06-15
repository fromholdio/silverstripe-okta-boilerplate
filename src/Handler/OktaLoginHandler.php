<?php

namespace NSWDPC\Authentication\Okta;

use Bigfork\SilverStripeOAuth\Client\Model\Passport;
use Bigfork\SilverStripeOAuth\Client\Handler\LoginTokenHandler;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Security\IdentityStore;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Perform Okta login handling without user/member Email collisions
 */
class OktaLoginHandler extends LoginTokenHandler
{
    use Configurable;
    use OktaGroups;

    /**
     * @var bool
     * If true, link to an existing member based on Email address
     * Note: this assumes that the owner of the Okta email address is the owner of
     * the Silvertripe Member.Email address
     * If you cannot ensure that, set this value in your project configuration to false
     */
    private static $link_existing_member = true;

    /**
     * @var bool
     * If true, after authentication the user's Okta groups will be used to determine
     * authenticated access
     * see self::assignGroups() for group scope and return setup
     */
    private static $apply_group_restriction = true;

    /**
     * @var array
     * The user must be in all groups listed here to gain authenticated access to the site
     * If empty then all users authenticating via OAuth can gain access
     */
    private static $site_restricted_groups = [];

    /**
     * List of failure codes
     */
    const FAIL_USER_NO_GROUPS = 100;
    const FAIL_USER_MEMBER_COLLISION = 101;
    const FAIL_USER_MISSING_REQUIRED_GROUPS = 102;
    const FAIL_USER_MISSING_EMAIL = 103;
    const FAIL_USER_MEMBER_EMAIL_MISMATCH = 104;
    const FAIL_USER_MEMBER_PASSPORT_MISMATCH = 105;
    const FAIL_PASSPORT_CREATE_IDENT_COLLISION = 106;
    const FAIL_NO_PROVIDER_NAME = 200;
    const FAIL_NO_PASSPORT_NO_MEMBER_CREATED = 300;
    const FAIL_PASSPORT_NO_MEMBER_CREATED = 301;

    /*
     * @var string|null
     */
    protected $loginFailureCode = null;

    /*
     * @var int|null
     */
    protected $loginFailureMessageId = null;

    /**
     * @inheritdoc
     * Override parent::handleToken to:
     * - reset the login failure code
     * - work around some issues with validationCanLogin message handling
     */
    public function handleToken(AccessToken $token, AbstractProvider $provider)
    {
        try {
            $this->setLoginFailureCode(null);//reset failure code on new attempt
            // Find or create a member from the token
            $member = $this->findOrCreateMember($token, $provider);
        } catch (ValidationException $e) {
            return Security::permissionFailure(null, $e->getMessage());
        }

        // Check whether the member can log in before we proceed
        $result = $member->validateCanLogin();
        if (!$result->isValid()) {
            $message = implode("; ", array_map(
                function ($message) {
                    return $message['message'];
                },
                $result->getMessages()
            ));
            return Security::permissionFailure(
                null,
                $message
            );
        }

        // Log the member in
        $identityStore = Injector::inst()->get(IdentityStore::class);
        $identityStore->logIn($member);
        return null;
    }

    /**
     * Return message related to code
     */
    public static function getFailMessageForCode($code) : string
    {
        switch ($code) {
            case self::FAIL_USER_NO_GROUPS:
                return _t('OAUTH.FAIL_' . $code, 'User has no Okta groups');
                break;
            case self::FAIL_USER_MEMBER_COLLISION:
                return _t('OAUTH.FAIL_' . $code, 'User/member collision');
                break;
            case self::FAIL_USER_MISSING_REQUIRED_GROUPS:
                return _t('OAUTH.FAIL_' . $code, 'User missing required groups');
                break;
            case self::FAIL_USER_MISSING_EMAIL:
                return _t('OAUTH.FAIL_' . $code, 'User missing email');
                break;
            case self::FAIL_USER_MEMBER_EMAIL_MISMATCH:
                return _t('OAUTH.FAIL_' . $code, 'User/member email mismatch');
                break;
            case self::FAIL_USER_MEMBER_PASSPORT_MISMATCH:
                return _t('OAUTH.FAIL_' . $code, 'User/member/passport mismatch');
                break;
            case self::FAIL_PASSPORT_CREATE_IDENT_COLLISION:
                return _t('OAUTH.FAIL_' . $code, 'Tried to create a passport when one existed for the identifier/provider');
                break;
            case self::FAIL_NO_PROVIDER_NAME:
                return _t('OAUTH.FAIL_' . $code, 'No provider name');
                break;
            case self::FAIL_NO_PASSPORT_NO_MEMBER_CREATED:
                return _t('OAUTH.FAIL_' . $code, 'No passport found and no member created');
                break;
            case self::FAIL_PASSPORT_NO_MEMBER_CREATED:
                return _t('OAUTH.FAIL_' . $code, 'Passport created but no member found');
                break;
            default:
                return _t('OAUTH.FAIL_UNKNOWN_CODE', 'Unknown');
                break;
        }
    }

    /**
     * @param string|null $code
     */
    protected function setLoginFailureCode($code, $userId = '')
    {
        $messageId = null;
        if ($code) {
            // a random message id a user can quote to support
            $messageId = random_int(100000, 1000000);
            $session = $this->getSession();
            $providerName = $session->get('oauth2.provider');
            OAuthLog::add(
                $code,
                $messageId,
                $providerName,
                $userId
            );
        }
        $this->loginFailureMessageId = $messageId;
        $this->loginFailureCode = $code;
    }

    /**
     * @return string|null
     */
    public function getLoginFailureCode()
    {
        return $this->loginFailureCode;
    }

    /**
     * @return int|null
     */
    public function getLoginFailureMessageId()
    {
        return $this->loginFailureMessageId;
    }

    /**
     * Generic support message
     * @return string
     */
    public function getSupportMessage() : string
    {
        return _t(
            'OAUTH.SUPPORT_MESSAGE',
            'Sorry, there was an issue signing you in. Please try again or contact support.'
        );
    }

    /**
     * Given an identifier and a provider string, return the Passport matching
     * @return Passport|null
     */
    protected function getPassport(string $identifier, string $provider)
    {
        $passport = Passport::get()->filter([
            'Identifier' => $identifier,
            'OAuthSource' => $provider
        ])->first();
        return $passport;
    }

    /**
     * Create a passport with the provided identifier, a provider string and a Member record
     * See {@link NSWDPC\Authentication\Okta\PassportExtension::validatePassportWrite()}
     * @return Passport|null
     */
    protected function createPassport(string $identifier, string $provider, Member $member) : Passport
    {
        try {
            // create a passport
            $passport = Passport::create([
                'Identifier' => $identifier,
                'OAuthSource' => $provider,
                'MemberID' => $member->ID
            ]);
            $passport->write();
            if (!$passport->isInDB()) {
                return null;
            } else {
                return $passport;
            }
        } catch (ValidationException $e) {
            // catch the validation exception thrown on write error
            $this->setLoginFailureCode( $e->getCode(), $identifier);
            // rethrow with the login exception message
            throw new ValidationException(
                _t(
                    'OKTA.INVALID_MEMBER',
                    '{getSupportMessage} (#{messageId})',
                    [
                        'messageId' => $this->getLoginFailureMessageId()
                    ]
                )
            );    
        }
    }

    /**
     * Apply configured group restrictions based on Okta groups returned
     * Returns false when no restriction applied due to configuration, true if user passes checks
     * throw ValidationException if check fails
     * @return bool
     * @throws ValidationException
     * @todo move to Okta control panel in /admin area
     */
    protected function applyOktaGroupRestriction(ResourceOwnerInterface $user)
    {

        // check if restricting
        if (!$this->config()->get('apply_group_restriction')) {
            return false;
        }

        // get user Okta groups (titles)
        $data = $user->toArray();
        if (empty($data['groups']) || !is_array($data['groups'])) {
            $this->setLoginFailureCode(self::FAIL_USER_NO_GROUPS, $user->getId());
            throw new ValidationException(
                _t(
                    'OKTA.GENERAL_SESSION_ERROR',
                    '{getSupportMessage} (#{messageId})',
                    [
                        'messageId' => $this->getLoginFailureMessageId(),
                        'getSupportMessage' => $this->getSupportMessage()
                    ]
                )
            );
        }

        // User group check
        $restrictedGroups = $this->config()->get('site_restricted_groups');

        if (empty($restrictedGroups)) {
            // no restrictions
            return true;
        }

        if (!is_array($restrictedGroups)) {
            // assume a single group
            $restrictedGroups = [ $restrictedGroups ];
        }

        $intersect = array_intersect($restrictedGroups, $data['groups']);
        // the intersect must contain all the restricted groups
        if ($intersect != $restrictedGroups) {
            $this->setLoginFailureCode(self::FAIL_USER_MISSING_REQUIRED_GROUPS, $user->getId());
            throw new ValidationException(
                _t(
                    'OKTA.GENERAL_SESSION_ERROR',
                    '{getSupportMessage} (#{messageId})',
                    [
                        'messageId' => $this->getLoginFailureMessageId(),
                        'getSupportMessage' => $this->getSupportMessage()
                    ]
                )
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function findOrCreateMember(AccessToken $token, AbstractProvider $provider)
    {
        $session = $this->getSession();

        $user = $provider->getResourceOwner($token);

        $this->applyOktaGroupRestriction($user);

        $identifier = $user->getId();
        $providerName = $session->get('oauth2.provider');

        $userEmail = $user->getEmail();
        if (!$userEmail) {
            $this->setLoginFailureCode(self::FAIL_USER_MISSING_EMAIL, $user->getId());
            throw new ValidationException(
                _t(
                    'OKTA.GENERAL_SESSION_ERROR',
                    '{getSupportMessage} (#{messageId})',
                    [
                        'messageId' => $this->getLoginFailureMessageId(),
                        'getSupportMessage' => $this->getSupportMessage()
                    ]
                )
            );
        }

        if (empty($providerName)) {
            $this->setLoginFailureCode(self::FAIL_NO_PROVIDER_NAME, $user->getId());
            throw new ValidationException(
                _t(
                    'OKTA.GENERAL_SESSION_ERROR',
                    '{getSupportMessage} (#{messageId})',
                    [
                        'messageId' => $this->getLoginFailureMessageId(),
                        'getSupportMessage' => $this->getSupportMessage()
                    ]
                )
            );
        }

        /** @var Passport $passport */
        $passport = $this->getPassport($identifier, $providerName);
        if (!$passport) {
            // Create the new member (or link to a matching one if config allows)
            $member = $this->createMember($token, $provider);
            // handle no member being created/linked
            if (!$member) {
                $this->setLoginFailureCode(self::FAIL_NO_PASSPORT_NO_MEMBER_CREATED, $user->getId());
                throw new ValidationException(
                    _t(
                        'OKTA.INVALID_MEMBER',
                        '{getSupportMessage} (#{messageId})',
                        [
                            'messageId' => $this->getLoginFailureMessageId()
                        ]
                    )
                );
            }
            // Assign member to created passport
            $passport = $this->createPassport($identifier, $providerName, $member);
        } else {
            // Passport exists
            $member = $passport->Member();
            // Handle the local member no longer existing
            if (!$member) {
                $member = $this->createMember($token, $provider);
                // handle no member being created/linked
                if (!$member) {
                    $this->setLoginFailureCode(self::FAIL_PASSPORT_NO_MEMBER_CREATED, $user->getId());
                    throw new ValidationException(
                        _t(
                            'OKTA.INVALID_MEMBER',
                            '{getSupportMessage} (#{messageId})',
                            [
                                'messageId' => $this->getLoginFailureMessageId()
                            ]
                        )
                    );
                }
                $passport->MemberID = $member->ID;
                $passport->write();
            }
        }

        $this->assignGroups($user, $member);

        return $member;
    }

    /**
     * Given a user returned from Okta, assign their configured groups
     * if the groups were also returned
     * Groups are returned by adding the 'group' scope to the AccessToken claim
     * You must add a "Groups claim filter" = 'groups' 'Matches regex' '.*' in the Service app
     * OpenID Connect ID Token section
     * @see https://developer.okta.com/docs/guides/customize-tokens-groups-claim/add-groups-claim-org-as/
     * @return array values are created or updated Group.ID values for the $member
     * @param ResourceOwnerInterface $user an Okta user
     * @param Member $member associated with the Okta user
     */
    protected function assignGroups(ResourceOwnerInterface $user, Member $member) : array
    {

        // groups are present in the returned user, but not via a method
        $data = $user->toArray();

        // Note: to return all the user's Okta groups, the groups claim in the application settings should be
        // .* to return all the user's groups
        // the list of groups here may not represent a list of all the user's Okta groups
        // the goal here is to sync on auth the available Okta groups
        $groups = !empty($data['groups']) && is_array($data['groups']) ? $data['groups'] : [];

        return $this->oktaUserMemberGroupAssignment($groups, $member);
    }

    /**
     * Create a member from the given token
     *
     * @param AccessToken $token
     * @param AbstractProvider $provider
     * @return Member
     * @throws ValidationException
     */
    protected function createMember(AccessToken $token, AbstractProvider $provider)
    {
        $session = $this->getSession();
        $providerName = $session->get('oauth2.provider');
        $user = $provider->getResourceOwner($token);
        $userEmail = $user->getEmail();

        // require a user email for this operation
        if (!$userEmail) {
            $this->setLoginFailureCode(self::FAIL_USER_MISSING_EMAIL, $user->getId());
            throw new ValidationException(
                _t(
                    'OKTA.NO_EMAIL_RETURNED',
                    '{getSupportMessage} (#{messageId})',
                    [
                        'messageId' => $this->getLoginFailureMessageId(),
                        'getSupportMessage' => $this->getSupportMessage()
                    ]
                )
            );
        }

        // require a provider name for this operation
        if (empty($providerName)) {
            $this->setLoginFailureCode(self::FAIL_NO_PROVIDER_NAME, $user->getId());
            throw new ValidationException(
                _t(
                    'OKTA.GENERAL_SESSION_ERROR',
                    '{getSupportMessage} (#{messageId})',
                    [
                        'messageId' => $this->getLoginFailureMessageId(),
                        'getSupportMessage' => $this->getSupportMessage()
                    ]
                )
            );
        }

        /* @var Member|null */
        $member = Member::get()->filter('Email', $userEmail)->first();
        if (!$member) {
            // no existing member for the user's email address, can create one
            $member = Member::create();
            $member = $this->getMapper($providerName)->map($member, $user);
            // Retained for compat with LoginTokenHandler
            $member->OAuthSource = null;
            $member->write();
        } elseif ($this->config()->get('link_existing_member')) {
            // Member exists, update mapped fields from the provider
            $member = $this->getMapper($providerName)->map($member, $user);
            $member->OAuthSource = null;
            $member->write();
        } else {
            // Member exists, but collision detected and config disallows linking
            $this->setLoginFailureCode(self::FAIL_USER_MEMBER_COLLISION, $user->getId());
            throw new ValidationException(
                _t(
                    'OKTA.MEMBER_COLLISION',
                    '{getSupportMessage} (#{messageId})',
                    [
                        'messageId' => $this->getLoginFailureMessageId(),
                        'getSupportMessage' => $this->getSupportMessage()
                    ]
                )
            );
        }
        return $member;
    }
}

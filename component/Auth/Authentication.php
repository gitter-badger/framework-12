<?php

namespace Perfumer\Component\Auth;

use App\Model\Token;
use App\Model\TokenQuery;
use App\Model\User;
use App\Model\UserQuery;
use Perfumer\Component\Auth\Exception\AuthException;
use Perfumer\Component\Auth\TokenHandler\AbstractHandler as TokenHandler;
use Perfumer\Component\Session\Core as SessionService;
use Perfumer\Component\Session\Item as Session;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Map\TableMap;

class Authentication
{
    const STATUS_ACCOUNT_BANNED = 1;
    const STATUS_ACCOUNT_DISABLED = 2;
    const STATUS_ANONYMOUS = 3;
    const STATUS_AUTHENTICATED = 4;
    const STATUS_INVALID_CREDENTIALS = 5;
    const STATUS_INVALID_GROUP = 6;
    const STATUS_INVALID_PASSWORD = 7;
    const STATUS_INVALID_USERNAME = 8;
    const STATUS_NO_TOKEN = 9;
    const STATUS_NON_EXISTING_TOKEN = 10;
    const STATUS_NON_EXISTING_USER = 11;
    const STATUS_REMOTE_SERVER_ERROR = 12;
    const STATUS_SIGNED_IN = 13;
    const STATUS_SIGNED_OUT = 14;

    /**
     * @var SessionService
     */
    protected $session_service;

    /**
     * @var TokenHandler
     */
    protected $token_handler;

    /**
     * @var \App\Model\User
     */
    protected $user;

    /**
     * @var Session
     */
    protected $session;

    protected $token;
    protected $status;

    protected $options = [];

    public function __construct(SessionService $session_service, TokenHandler $token_handler, $options = [])
    {
        $default_options = [
            'update_gap' => 3600,
            'groups' => []
        ];

        $this->options = array_merge($default_options, $options);

        $this->session_service = $session_service;
        $this->token_handler = $token_handler;
        $this->user = new User();

        $this->token = $this->token_handler->getToken();

        if ($this->token !== null)
            $this->session = $this->session_service->get($this->token);
    }

    public function isLogged()
    {
        if ($this->status === null)
            $this->init();

        return $this->user->isLogged();
    }

    public function getUser()
    {
        if ($this->status === null)
            $this->init();

        return $this->user;
    }

    public function getStatus()
    {
        if ($this->status === null)
            $this->init();

        return $this->status;
    }

    public function getSession()
    {
        if ($this->session === null || $this->session->isDestroyed())
        {
            $this->session = $this->session_service->get();

            $this->token_handler->setToken($this->session->getId());
        }

        return $this->session;
    }

    public function getToken()
    {
        return $this->token;
    }

    public function init()
    {
        $start_session = false;
        $update_session = false;

        try
        {
            if ($this->token === null)
                throw new AuthException(self::STATUS_NO_TOKEN);

            $user = null;

            if (!$this->session_service->has($this->token))
            {
                $token = TokenQuery::create()->findOneByToken($this->token);

                if (!$token)
                    throw new AuthException(self::STATUS_NON_EXISTING_TOKEN);

                $user = $token->getUser();

                $start_session = true;
            }
            else
            {
                $_user = $this->session->get('_user');

                if (isset($_user['data']['id']))
                {
                    if(time() - $this->session->get('_updated_at') >= $this->options['update_gap'])
                    {
                        $user = UserQuery::create()->findPk($_user['data']['id']);

                        if (!$user)
                            throw new AuthException(self::STATUS_NON_EXISTING_USER);

                        $update_session = true;
                    }
                    else
                    {
                        $user = new User();
                        $user->fromArray($_user['data'], TableMap::TYPE_FIELDNAME);
                        $user->setPermissions($_user['permissions']);
                        $user->setRoleIds($_user['role_ids']);
                        $user->setNew(false);
                    }
                }
                else
                {
                    // This is anonymous user, but has some data in session
                    $this->status = self::STATUS_ANONYMOUS;

                    $this->token_handler->setToken($this->token);

                    return;
                }
            }

            if ($this->options['groups'] && !$user->inGroup($this->options['groups']))
                throw new AuthException(self::STATUS_INVALID_GROUP);

            if ($user->isDisabled())
                throw new AuthException(self::STATUS_ACCOUNT_DISABLED);

            if ($user->getBannedTill() !== null && $user->getBannedTill()->diff(new \DateTime())->invert == 1)
                throw new AuthException(self::STATUS_ACCOUNT_BANNED);

            $this->user = $user;
            $this->user->setLogged(true);

            if ($start_session)
                $this->startSession();

            if ($update_session)
                $this->updateSession();

            $this->status = self::STATUS_AUTHENTICATED;

            $this->token_handler->setToken($this->token);
        }
        catch (AuthException $e)
        {
            $this->reset($e->getMessage());
        }
    }

    public function logout()
    {
        $this->reset(self::STATUS_SIGNED_OUT);
    }

    protected function startSession()
    {
        if ($this->session !== null)
            $this->session->destroy();

        $this->session = $this->session_service->get();

        $this->updateSessionData();

        $this->token = $this->session->getId();

        $token = new Token();
        $token->setToken($this->token);
        $token->setUser($this->user);

        $lifetime = $this->token_handler->getTokenLifetime();

        if ($lifetime > 0)
        {
            $expired_at = (new \DateTime())->modify('+' . $lifetime . ' second');

            $token->setExpiredAt($expired_at);
        }

        $token->save();

        // Clear old tokens in the database
        $expired_at = (new \DateTime())->modify('-' . $this->options['update_gap'] . ' second');

        TokenQuery::create()
            ->filterByUser($this->user)
            ->filterByExpiredAt($expired_at, '<')
            ->filterByExpiredAt(null, Criteria::ISNOTNULL)
            ->delete();
    }

    protected function updateSession()
    {
        $lifetime = $this->token_handler->getTokenLifetime();

        if ($lifetime > 0)
        {
            $token = TokenQuery::create()->findOneByToken($this->token);

            if ($token)
            {
                $expired_at = (new \DateTime())->modify('+' . $lifetime . ' second');

                $token->setExpiredAt($expired_at);
                $token->save();
            }
        }

        $this->updateSessionData();
    }

    protected function updateSessionData()
    {
        $this->user->revealRoles();

        $_user = [
            'data' => $this->user->toArray(TableMap::TYPE_FIELDNAME),
            'permissions' => $this->user->getPermissions(),
            'role_ids' => $this->user->getRoleIds()
        ];

        $this->session->set('_user', $_user)->set('_updated_at', time());
    }

    public function invalidateSessions($user = null)
    {
        if ($user === null)
            $user = $this->user;

        $tokens = TokenQuery::create()
            ->filterByUser($user)
            ->filterByExpiredAt(new \DateTime(), '>=')
            ->_or()
            ->filterByExpiredAt(null, Criteria::ISNULL)
            ->find();

        foreach ($tokens as $token)
        {
            $session = $this->session_service->get($token->getToken());

            if ($session->has('_updated_at'))
                $session->set('_updated_at', 0);
        }
    }

    protected function reset($status)
    {
        $this->user = new User();

        $this->status = $status;

        if ($this->session !== null)
            $this->session->destroy();

        if ($this->token !== null)
            $this->token_handler->deleteToken();
    }
}
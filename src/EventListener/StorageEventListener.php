<?php
namespace Bolt\EventListener;

use Bolt\AccessControl\Permissions;
use Bolt\Config;
use Bolt\Events\HydrationEvent;
use Bolt\Events\StorageEvent;
use Bolt\Events\StorageEvents;
use Bolt\Exception\AccessControlException;
use Bolt\Logger\FlashLoggerInterface;
use Bolt\Storage\Database\Schema\Manager;
use Bolt\Storage\Entity;
use Bolt\Storage\EntityManager;
use Bolt\Translation\Translator as Trans;
use PasswordLib\PasswordLib;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class StorageEventListener implements EventSubscriberInterface
{
    /** @var \Bolt\Storage\EntityManager */
    protected $em;
    /** @var \Bolt\Config */
    protected $config;
    /** @var \Bolt\Storage\Database\Schema\Manager */
    protected $schemaManager;
    /** @var UrlGeneratorInterface */
    protected $urlGenerator;
    /** @var \Bolt\Logger\FlashLoggerInterface */
    protected $loggerFlash;
    /** @var integer */
    protected $hashStrength;

    /**
     * Constructor.
     *
     * @param EntityManager        $em
     * @param Config               $config
     * @param Manager              $schemaManager
     * @param FlashLoggerInterface $loggerFlash
     * @param integer              $hashStrength
     */
    public function __construct(EntityManager $em, Config $config, Manager $schemaManager, UrlGeneratorInterface $urlGenerator, FlashLoggerInterface $loggerFlash, $hashStrength)
    {
        $this->em = $em;
        $this->config = $config;
        $this->schemaManager = $schemaManager;
        $this->urlGenerator = $urlGenerator;
        $this->loggerFlash = $loggerFlash;
        $this->hashStrength = $hashStrength;
    }

    /**
     * Pre-save storage event.
     *
     * @param StorageEvent $event
     */
    public function onPreSave(StorageEvent $event)
    {
        /** @var Entity\Entity $entityRecord */
        $entityRecord = $event->getContent();

        if ($entityRecord instanceof Entity\Users) {
            $this->passwordHash($entityRecord);
            $this->enableUser($entityRecord);
        }
    }

    /**
     * Post hydration storage event.
     *
     * @param HydrationEvent $event
     */
    public function onPostHydrate(HydrationEvent $event)
    {
        $entity = $event->getSubject();
        if (!$entity instanceof Entity\Users) {
            return;
        }

        // Ensure Permissions::ROLE_EVERYONE always exists
        $roles = $entity->getRoles();
        if (!in_array(Permissions::ROLE_EVERYONE, $roles)) {
            $roles[] = Permissions::ROLE_EVERYONE;
            $entity->setRoles($roles);
        }
    }

    /**
     * Kernel request listener callback.
     *
     * @param GetResponseEvent $event
     */
    public function onKernelRequest(GetResponseEvent $event)
    {
        if (!$event->isMasterRequest()) {
            return;
        }
        $this->schemaCheck($event);

        $contenttypes = $this->config->get('contenttypes', []);
        foreach ($contenttypes as $contenttype) {
            $contenttype = $this->em->getContentType($contenttype['slug']);

            // Check if we need to 'publish' any 'timed' records, or 'depublish' any expired records.
            $this->em->publishTimedRecords($contenttype);
            $this->em->depublishExpiredRecords($contenttype);
        }
    }

    /**
     * Trigger database schema checks if required.
     *
     * @param GetResponseEvent $event
     */
    protected function schemaCheck(GetResponseEvent $event)
    {
        $session = $event->getRequest()->getSession();
        $validSession = $session->isStarted() && $session->get('authentication');
        $expired = $this->schemaManager->isCheckRequired();
        if ($validSession && $expired && $this->schemaManager->isUpdateRequired()) {
            $msg = Trans::__(
                "The database needs to be updated/repaired. Go to 'Configuration' > '<a href=\"%link%\">Check Database</a>' to do this now.",
                ['%link%' => $this->urlGenerator->generate('dbcheck')]
                );
            $this->loggerFlash->error($msg);
        }
    }

    /**
     * Hash user passwords on save.
     *
     * @param Entity\Users $usersEntity
     */
    protected function passwordHash(Entity\Users $usersEntity)
    {
        if ($usersEntity->getPassword() !== null) {
            $usersEntity->setPassword($this->getValidHash($usersEntity->getPassword()));
        }
    }

    /**
     * Return a valid hash for a password, of if the password is already hashed
     * just return as is.
     *
     * @param string $password
     *
     * @return string
     */
    private function getValidHash($password)
    {
        if (strlen($password) === 60 && strpos($password, '$2y$') === 0) {
            return $password;
        }
        if (strlen($password) === 34 && strpos($password, '$P$') === 0) {
            return $password;
        }
        if (strlen($password) < 6) {
            throw new AccessControlException('Can not save a password with a length shorter than 6 characters!');
        }

        return (new PasswordLib())->createPasswordHash($password, '$2y$', ['cost' => $this->hashStrength]);
    }

    /**
     * Set user as enabled if user is new
     *
     * @param Entity\Users $usersEntity
     */
    protected function enableUser(Entity\Users $usersEntity)
    {
        if ($usersEntity->getId() === null) {
            $usersEntity->setEnabled(true);
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST       => ['onKernelRequest', 31],
            StorageEvents::PRE_SAVE     => 'onPreSave',
            StorageEvents::POST_HYDRATE => 'onPostHydrate',
        ];
    }
}

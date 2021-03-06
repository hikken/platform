<?php

namespace Oro\Bundle\EmailBundle\EventListener;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;

use Oro\Bundle\ActivityListBundle\Provider\ActivityListChainProvider;
use Oro\Bundle\AttachmentBundle\EntityConfig\AttachmentScope;
use Oro\Bundle\EmailBundle\Event\EmailBodyAdded;
use Oro\Bundle\EmailBundle\Manager\EmailAttachmentManager;
use Oro\Bundle\EmailBundle\Provider\EmailActivityListProvider;
use Oro\Bundle\EntityConfigBundle\DependencyInjection\Utils\ServiceLink;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;
use Oro\Bundle\SecurityBundle\SecurityFacade;

class EmailBodyAddListener
{
    const LINK_ATTACHMENT_CONFIG_OPTION = 'auto_link_attachments';

    /** @var ConfigProvider */
    protected $configProvider;

    /** @var EmailAttachmentManager */
    protected $attachmentManager;

    /** @var EmailActivityListProvider */
    protected $activityListProvider;

    /** @var SecurityFacade */
    protected $securityFacade;

    /** @var ActivityListChainProvider */
    protected $chainProvider;

    /** @var EntityManager */
    protected $entityManager;

    /**
     * @param EmailAttachmentManager $attachmentManager
     * @param ConfigProvider $configProvider
     * @param EmailActivityListProvider $activityListProvider
     * @param ServiceLink $securityFacadeLink
     * @param ActivityListChainProvider $chainProvider
     * @param EntityManager $entityManager
     */
    public function __construct(
        EmailAttachmentManager $attachmentManager,
        ConfigProvider $configProvider,
        EmailActivityListProvider $activityListProvider,
        ServiceLink $securityFacadeLink,
        ActivityListChainProvider $chainProvider,
        EntityManager $entityManager
    ) {
        $this->attachmentManager = $attachmentManager;
        $this->configProvider = $configProvider;
        $this->activityListProvider = $activityListProvider;
        $this->securityFacade = $securityFacadeLink->getService();
        $this->chainProvider = $chainProvider;
        $this->entityManager = $entityManager;
    }

    /**
     * @param EmailBodyAdded $event
     */
    public function linkToScope(EmailBodyAdded $event)
    {
        if ($this->securityFacade->getToken() !== null
            && !$this->securityFacade->isGranted('CREATE', 'entity:' . AttachmentScope::ATTACHMENT)
        ) {
            return;
        }
        $email = $event->getEmail();
        $entities = $this->activityListProvider->getTargetEntities($email);
        foreach ($entities as $entity) {
            if ((bool)$this->configProvider->getConfig(ClassUtils::getClass($entity))->get('auto_link_attachments')) {
                foreach ($email->getEmailBody()->getAttachments() as $attachment) {
                    $this->attachmentManager->linkEmailAttachmentToTargetEntity($attachment, $entity);
                }
            }
        }
    }

    /**
     * @param EmailBodyAdded $event
     *
     * @throws \Exception
     */
    public function updateActivityDescription(EmailBodyAdded $event)
    {
        $this->entityManager->beginTransaction();
        try {
            $email = $event->getEmail();
            $activityList = $this->chainProvider->getUpdatedActivityList($email, $this->entityManager);
            if ($activityList) {
                $this->entityManager->persist($activityList);
                $this->entityManager->flush();
            }
            $this->entityManager->commit();
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }
}

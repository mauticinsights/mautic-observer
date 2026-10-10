<?php

declare(strict_types=1);

/**
 * Minimal Mautic definitions for PHPStan in this repo (mautic/core-lib is not installed).
 *
 * @see https://phpstan.org/user-guide/stub-files
 */

namespace Mautic\ConfigBundle;

final class ConfigEvents
{
    public const CONFIG_PRE_SAVE = 'mautic.config.pre_save';
}

namespace Mautic\ConfigBundle\Event;

class ConfigEvent
{
    /**
     * @param array<string, mixed> $configs
     */
    public function __construct(private array $configs = [])
    {
    }

    public function getConfig(string $key)
    {
        return $this->configs[$key] ?? [];
    }
}

namespace Mautic\CoreBundle;

final class CoreEvents
{
    public const MAINTENANCE_CLEANUP_DATA = 'mautic.maintenance_cleanup_data';
}

namespace Mautic\CoreBundle\Event;

class MaintenanceEvent extends \Symfony\Contracts\EventDispatcher\Event
{
    private int $daysOld;

    private bool $dryRun;

    private bool $gdpr;

    public function __construct(int $daysOld, bool $dryRun, bool $gdpr)
    {
        $this->daysOld = $daysOld;
        $this->dryRun = $dryRun;
        $this->gdpr = $gdpr;
    }

    public function getDays(): int
    {
        return $this->daysOld;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function isGdpr(): bool
    {
        return $this->gdpr;
    }
}

namespace Mautic\CoreBundle\Helper;

class AppVersion
{
    public function getVersion(): string
    {
        return '';
    }
}

class CoreParametersHelper
{
    public function get(string $name)
    {
        return null;
    }
}

class EncryptionHelper
{
    /**
     * @return mixed|false
     */
    public function decrypt($key, bool $mainDecryptOnly = false)
    {
        return $key;
    }
}

namespace Mautic\CoreBundle\Form\Type;

class YesNoButtonGroupType
{
}

namespace Mautic\IntegrationsBundle\Integration\Interfaces;

use Mautic\PluginBundle\Entity\Integration;

interface ConfigFormFeatureSettingsInterface
{
    public function getFeatureSettingsConfigFormName(): string;
}

interface IntegrationInterface
{
    public function getName(): string;

    public function getDisplayName(): string;

    public function hasIntegrationConfiguration(): bool;

    public function getIntegrationConfiguration(): Integration;

    public function setIntegrationConfiguration(Integration $integration): void;
}

namespace Mautic\IntegrationsBundle\Integration;

use Mautic\PluginBundle\Entity\Integration;

class BasicIntegration
{
    public function setIntegrationSettings(Integration $integration): void
    {
    }
}

namespace Mautic\PluginBundle\Entity;

class Integration
{
    /**
     * @return array<string, mixed>
     */
    public function getFeatureSettings(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $featureSettings
     */
    public function setFeatureSettings(array $featureSettings): void
    {
    }

    public function getIsPublished(): bool
    {
        return false;
    }

    public function setIsPublished(bool $published): void
    {
    }

    public function getName(): string
    {
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function getApiKeys(): array
    {
        return [];
    }
}

namespace Mautic\CoreBundle\Model;

class NotificationModel
{
    public function addNotification(
        $message,
        $type = null,
        $isRead = false,
        $header = null,
        $iconClass = null,
        ?\DateTime $datetime = null,
        $user = null,
        ?string $deduplicateValue = null,
        ?\DateTime $deduplicateDateTimeFrom = null,
    ): void {
    }
}

namespace Mautic\UserBundle\Entity;

class User
{
    /**
     * @return int|null
     */
    public function getId()
    {
        return null;
    }

    public function isAdmin(): bool
    {
        return false;
    }

    public function getTimezone(): string
    {
        return '';
    }

    public function getLocale(): string
    {
        return '';
    }
}

namespace Mautic\CoreBundle\Helper;

class UserHelper
{
    public function getUser(bool $nullIfGuest = false): ?\Mautic\UserBundle\Entity\User
    {
        return null;
    }
}

namespace Mautic\PluginBundle;

final class PluginEvents
{
    public const PLUGIN_ON_INTEGRATION_CONFIG_SAVE = 'mautic.plugin_on_integration_config_save';
    public const ON_PLUGIN_UPDATE = 'mautic.plugin.on_plugin_update';
    public const ON_PLUGIN_INSTALL = 'mautic.plugin.on_plugin_install';
}

namespace Mautic\PluginBundle\Entity;

class Plugin
{
    public function getName(): string
    {
        return '';
    }

    public function getBundle(): string
    {
        return '';
    }
}

namespace Mautic\PluginBundle\Event;

use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Entity\Plugin;

class PluginIntegrationEvent
{
    public function __construct(private Integration $integration)
    {
    }

    public function getEntity(): Integration
    {
        return $this->integration;
    }
}

class PluginUpdateEvent
{
    public function __construct(private Plugin $plugin, private string $oldVersion)
    {
    }

    public function getPlugin(): Plugin
    {
        return $this->plugin;
    }

    public function getOldVersion(): string
    {
        return $this->oldVersion;
    }

    public function checkContext(string $pluginName): bool
    {
        return $pluginName === $this->plugin->getName();
    }
}

class PluginInstallEvent
{
    public function __construct(private Plugin $plugin)
    {
    }

    public function getPlugin(): Plugin
    {
        return $this->plugin;
    }

    public function checkContext(string $pluginName): bool
    {
        return $pluginName === $this->plugin->getName();
    }
}

namespace Mautic\IntegrationsBundle;

final class IntegrationEvents
{
    public const INTEGRATION_CONFIG_AFTER_SAVE = 'mautic.integration_config_after_save';
}

namespace Mautic\IntegrationsBundle\Event;

use Mautic\PluginBundle\Entity\Integration;

class ConfigSaveEvent
{
    public function __construct(private Integration $integration)
    {
    }

    public function getIntegrationConfiguration(): Integration
    {
        return $this->integration;
    }
}

namespace Mautic\CampaignBundle\Entity;

class Campaign
{
    public ?\DateTimeInterface $deleted = null;

    public function getId(): ?int
    {
        return null;
    }

    public function getDeletedId(): ?int
    {
        return null;
    }

    public function getName(): string
    {
        return '';
    }

    public function isPublished(): bool
    {
        return false;
    }
}

namespace Mautic\LeadBundle\Entity;

class Lead
{
    public ?\DateTimeInterface $dateIdentified = null;

    public function getId(): ?int
    {
        return null;
    }

    public function getDeletedId(): ?int
    {
        return null;
    }

    public function isAnonymous(): bool
    {
        return false;
    }

    public function getEmail(): ?string
    {
        return null;
    }

    /**
     * @param bool $lastFirst
     */
    public function getPrimaryIdentifier($lastFirst = false): ?string
    {
        return null;
    }

    public function getCompany(): ?string
    {
        return null;
    }

    public function getFirstname(): ?string
    {
        return null;
    }

    public function getLastname(): ?string
    {
        return null;
    }

    /**
     * @return iterable<object>
     */
    public function getTags(): iterable
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getChanges(bool $includePast = false): array
    {
        return [];
    }
}

class LeadDevice
{
    public function getDevice(): ?string
    {
        return null;
    }

    public function getDeviceOsName(): ?string
    {
        return null;
    }

    public function getDeviceOsShortName(): ?string
    {
        return null;
    }

    public function getDeviceOsVersion(): ?string
    {
        return null;
    }

    public function getDeviceOsPlatform(): ?string
    {
        return null;
    }

    public function getDeviceBrand(): ?string
    {
        return null;
    }

    public function getDeviceModel(): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getClientInfo(): array
    {
        return [];
    }
}

class LeadList
{
    public ?int $deletedId = null;

    public function getId(): ?int
    {
        return null;
    }

    public function getDeletedId(): ?int
    {
        return null;
    }

    public function getName(): string
    {
        return '';
    }

    public function getAlias(): string
    {
        return '';
    }

    public function getPublicName(): ?string
    {
        return null;
    }

    public function getIsPublished(): bool
    {
        return false;
    }
}

namespace Mautic\LeadBundle\Model;

use Mautic\LeadBundle\Entity\Lead;

class LeadModel
{
    /**
     * @return list<array<string, mixed>|object>
     */
    public function getLists(Lead $lead, bool $forLists = false, bool $arrayHydration = false, bool $isPublic = false, bool $isPreferenceCenter = false): array
    {
        return [];
    }
}

namespace Mautic\EmailBundle\Entity;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;

class Email
{
    public function getId(): ?int
    {
        return null;
    }

    public function getDeletedId(): ?int
    {
        return null;
    }

    public function getName(): string
    {
        return '';
    }

    public function getSubject(): string
    {
        return '';
    }

    public function getEmailType(): string
    {
        return 'template';
    }

    public function getIsPublished(): bool
    {
        return false;
    }

    /**
     * @return iterable<LeadList>
     */
    public function getLists()
    {
        return [];
    }
}

class Stat
{
    public function getEmail(): ?Email
    {
        return null;
    }

    public function getLead(): ?Lead
    {
        return null;
    }

    public function getSource(): ?string
    {
        return null;
    }

    public function getSourceId(): ?int
    {
        return null;
    }

    public function getList(): ?LeadList
    {
        return null;
    }
}

namespace Mautic\EmailBundle;

final class EmailEvents
{
    public const EMAIL_ON_SEND = 'mautic.email.on_send';

    public const EMAIL_ON_OPEN = 'mautic.email.on_open';

    public const EMAIL_ON_REPLY = 'mautic.email_on_reply';

    public const EMAIL_FAILED = 'mautic.on_email_failed';

    public const EMAIL_POST_SAVE = 'mautic.email_post_save';

    public const EMAIL_POST_DELETE = 'mautic.email_post_delete';
}

namespace Mautic\EmailBundle\Event;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\LeadBundle\Entity\Lead;

class EmailEvent
{
    public function __construct(private Email $email, private bool $isNew = false)
    {
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }
}

class EmailSendEvent
{
    public function isInternalSend(): bool
    {
        return false;
    }

    /**
     * @return Lead|array<string, mixed>|null
     */
    public function getLead()
    {
        return null;
    }

    public function getEmail(): ?Email
    {
        return null;
    }

    /**
     * @return array<int, mixed>
     */
    public function getSource(): array
    {
        return [];
    }
}

class EmailOpenEvent
{
    public function getEmail(): ?Email
    {
        return null;
    }

    public function getStat(): Stat
    {
        return new Stat();
    }

    public function isFirstTime(): bool
    {
        return false;
    }
}

class QueueEmailEvent
{
    public function getMessage(): \Mautic\EmailBundle\Mailer\Message\MauticMessage
    {
        return new \Mautic\EmailBundle\Mailer\Message\MauticMessage();
    }
}

class TransportWebhookEvent
{
    public function __construct(private \Symfony\Component\HttpFoundation\Request $request)
    {
    }

    public function getRequest(): \Symfony\Component\HttpFoundation\Request
    {
        return $this->request;
    }
}

namespace Mautic\EmailBundle\Mailer\Message;

class MauticMessage
{
    public function getLeadIdHash(): ?string
    {
        return null;
    }

    public function getSubject(): string
    {
        return '';
    }

    /**
     * @return list<object>
     */
    public function getFrom(): array
    {
        return [];
    }

    /**
     * @return list<object>
     */
    public function getTo(): array
    {
        return [];
    }
}

namespace Mautic\PointBundle\Entity;

class TriggerEvent
{
    public function getId(): ?int
    {
        return null;
    }

    public function getName(): string
    {
        return '';
    }

    public function getType(): string
    {
        return '';
    }
}

namespace Mautic\PointBundle\Event;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\PointBundle\Entity\TriggerEvent;

class TriggerExecutedEvent
{
    public function getLead(): Lead
    {
        return new Lead();
    }

    public function getTriggerEvent(): TriggerEvent
    {
        return new TriggerEvent();
    }
}

namespace Mautic\LeadBundle;

final class LeadEvents
{
    public const LEAD_POST_SAVE = 'mautic.lead_post_save';

    public const LEAD_PRE_DELETE = 'mautic.lead_pre_delete';

    public const LEAD_POST_DELETE = 'mautic.lead_post_delete';

    public const LEAD_POINTS_CHANGE = 'mautic.lead_points_change';

    public const LEAD_LIST_CHANGE = 'mautic.lead_list_change';

    public const LEAD_IDENTIFIED = 'mautic.lead_identified';

    public const LIST_POST_SAVE = 'mautic.lead_list_post_save';

    public const LIST_POST_DELETE = 'mautic.lead_list_post_delete';

    public const CHANNEL_SUBSCRIPTION_CHANGED = 'mautic.lead_channel_subscription_changed';
}

namespace Mautic\LeadBundle\Event;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;

class LeadEvent
{
    public function __construct(
        private Lead $lead,
        private bool $isNew = false,
    ) {
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    public function getLead(): Lead
    {
        return $this->lead;
    }
}

class LeadListEvent extends \Symfony\Contracts\EventDispatcher\Event
{
    public function __construct(
        private LeadList $list,
        private bool $isNew = false,
    ) {
    }

    public function getList(): LeadList
    {
        return $this->list;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }
}

class PointsChangeEvent
{
    public function __construct(
        private Lead $lead,
        private int $oldPoints,
        private int $newPoints,
    ) {
    }

    public function getLead(): Lead
    {
        return $this->lead;
    }

    public function getOldPoints(): int
    {
        return $this->oldPoints;
    }

    public function getNewPoints(): int
    {
        return $this->newPoints;
    }
}

class ListChangeEvent extends \Symfony\Contracts\EventDispatcher\Event
{
    private ?Lead $lead = null;

    /** @var list<Lead>|null */
    private ?array $leads = null;

    /**
     * @param Lead|list<Lead> $leadsOrLead
     */
    public function __construct(
        Lead|array $leadsOrLead,
        private LeadList $list,
        private bool $added = true,
    ) {
        if (\is_array($leadsOrLead)) {
            $this->leads = $leadsOrLead;
        } else {
            $this->lead = $leadsOrLead;
        }
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    /**
     * @return list<Lead>|null
     */
    public function getLeads(): ?array
    {
        return $this->leads;
    }

    public function getList(): LeadList
    {
        return $this->list;
    }

    public function wasAdded(): bool
    {
        return $this->added;
    }
}

class ChannelSubscriptionChange extends \Symfony\Contracts\EventDispatcher\Event
{
    public function __construct(
        private Lead $lead,
        private string $channel,
        private int $oldStatus,
        private int $newStatus,
    ) {
    }

    public function getLead(): Lead
    {
        return $this->lead;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getOldStatus(): int
    {
        return $this->oldStatus;
    }

    public function getNewStatus(): int
    {
        return $this->newStatus;
    }

    public function getOldStatusVerb(): string
    {
        return '';
    }

    public function getNewStatusVerb(): string
    {
        return '';
    }
}

namespace Mautic\PageBundle;

final class PageEvents
{
    public const PAGE_ON_HIT = 'mautic.page_on_hit';
}

namespace Mautic\PageBundle\Entity;

class Page
{
    public function __construct(
        private ?int $id = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}

class Hit
{
    public function __construct(
        private ?Page $page = null,
        private ?\Mautic\LeadBundle\Entity\Lead $lead = null,
    ) {
    }

    public function getId(): ?int
    {
        return null;
    }

    public function getCode(): ?int
    {
        return null;
    }

    public function getPage(): ?Page
    {
        return $this->page;
    }

    public function getLead(): ?\Mautic\LeadBundle\Entity\Lead
    {
        return $this->lead;
    }

    public function getTrackingId(): ?string
    {
        return null;
    }

    public function getDateHit(): ?\DateTimeInterface
    {
        return null;
    }

    public function getUrl(): ?string
    {
        return null;
    }

    public function getUrlTitle(): ?string
    {
        return null;
    }

    public function getReferer(): ?string
    {
        return null;
    }

    public function getPageLanguage(): ?string
    {
        return null;
    }

    public function getCountry(): ?string
    {
        return null;
    }

    public function getRegion(): ?string
    {
        return null;
    }

    public function getCity(): ?string
    {
        return null;
    }

    public function getIsp(): ?string
    {
        return null;
    }

    public function getOrganization(): ?string
    {
        return null;
    }

    public function getSource(): ?string
    {
        return null;
    }

    public function getSourceId(): ?int
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQuery(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function getBrowserLanguages(): array
    {
        return [];
    }

    public function getEmail(): ?\Mautic\EmailBundle\Entity\Email
    {
        return null;
    }

    public function getRedirect(): ?Redirect
    {
        return null;
    }

    public function getDeviceStat(): ?\Mautic\LeadBundle\Entity\LeadDevice
    {
        return null;
    }
}

class Redirect
{
    public function getId(): ?int
    {
        return null;
    }
}

namespace Mautic\PageBundle\Event;

use Mautic\PageBundle\Entity\Hit;
use Mautic\PageBundle\Entity\Page;

class PageHitEvent
{
    /**
     * @param array<string, mixed> $clickthroughData
     */
    public function __construct(
        private Hit $hit,
        private ?int $code = null,
        private array $clickthroughData = [],
        private bool $unique = false,
    ) {
    }

    public function getHit(): Hit
    {
        return $this->hit;
    }

    public function getPage(): ?Page
    {
        return $this->hit->getPage();
    }

    public function getCode(): ?int
    {
        return $this->code;
    }

    /**
     * @return array<string, mixed>
     */
    public function getClickthroughData(): array
    {
        return $this->clickthroughData;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }
}

namespace Mautic\FormBundle;

final class FormEvents
{
    public const FORM_ON_SUBMIT = 'mautic.form_on_submit';
}

namespace Mautic\FormBundle\Entity;

class Form
{
    public function __construct(
        private ?int $id = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}

class Submission
{
    public function __construct(
        private ?Form $form = null,
        private ?\Mautic\LeadBundle\Entity\Lead $lead = null,
    ) {
    }

    public function getForm(): ?Form
    {
        return $this->form;
    }

    public function getLead(): ?\Mautic\LeadBundle\Entity\Lead
    {
        return $this->lead;
    }
}

namespace Mautic\FormBundle\Event;

use Mautic\FormBundle\Entity\Submission;

class SubmissionEvent
{
    public function __construct(
        private Submission $submission,
    ) {
    }

    public function getSubmission(): Submission
    {
        return $this->submission;
    }
}

namespace Symfony\Contracts\Translation;

interface TranslatorInterface
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string;
}

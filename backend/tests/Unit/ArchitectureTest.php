<?php

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;
use SiteXPost\Modules\AiGateway\Domain\AiRequest;
use SiteXPost\Modules\Billing\Domain\BillingDocument;
use SiteXPost\Modules\Billing\Domain\BillingNotification;
use SiteXPost\Modules\Billing\Domain\BillingProfile;
use SiteXPost\Modules\Billing\Domain\CreditAdjustment;
use SiteXPost\Modules\Billing\Domain\CreditGrant;
use SiteXPost\Modules\Billing\Domain\CreditLedgerEntry;
use SiteXPost\Modules\Billing\Domain\CreditReservation;
use SiteXPost\Modules\Billing\Domain\CreditWallet;
use SiteXPost\Modules\Billing\Domain\PaymentOrder;
use SiteXPost\Modules\Billing\Domain\Refund;
use SiteXPost\Modules\Billing\Domain\Subscription;
use SiteXPost\Modules\Billing\Domain\SubscriptionChange;
use SiteXPost\Modules\Billing\Domain\SubscriptionEvent;
use SiteXPost\Modules\Billing\Domain\UsageEvent;
use SiteXPost\Modules\Billing\Domain\UsageMeter;
use SiteXPost\Modules\Research\Domain\CampaignSettings;
use SiteXPost\Modules\Research\Domain\CompetitorFinding;
use SiteXPost\Modules\Research\Domain\CompetitorWebsite;
use SiteXPost\Modules\Research\Domain\ContentCalendarItem;
use SiteXPost\Modules\Research\Domain\ContentGap;
use SiteXPost\Modules\Research\Domain\ContentIdea;
use SiteXPost\Modules\Research\Domain\ContentIdeaKeyword;
use SiteXPost\Modules\Research\Domain\InternalLinkSuggestion;
use SiteXPost\Modules\Research\Domain\Keyword;
use SiteXPost\Modules\Research\Domain\KeywordMetric;
use SiteXPost\Modules\Research\Domain\ResearchNotification;
use SiteXPost\Modules\Research\Domain\ResearchRun;
use SiteXPost\Modules\Research\Domain\ResearchSchedule;
use SiteXPost\Modules\Research\Domain\ResearchSource;
use SiteXPost\Modules\Research\Domain\ResearchUsageCounter;
use SiteXPost\Modules\Research\Domain\SearchConsoleConnection;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Domain\SourceSnapshot;
use SiteXPost\Modules\Research\Domain\TopicCluster;
use SiteXPost\Modules\Research\Domain\WebsiteKnowledge;
use SiteXPost\Modules\Research\Domain\WebsiteProfile;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SiteActivityLog;
use SiteXPost\Modules\Sites\Domain\SiteContentItem;
use SiteXPost\Modules\Sites\Domain\SiteCredential;
use SiteXPost\Modules\Sites\Domain\SiteHealthCheck;
use SiteXPost\Modules\Sites\Domain\SiteMedia;
use SiteXPost\Modules\Sites\Domain\SitePairing;
use SiteXPost\Modules\Sites\Domain\SitePublication;
use SiteXPost\Modules\Sites\Domain\SiteTaxonomy;
use SiteXPost\Modules\Workflows\Domain\WorkflowEvent;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Domain\WorkflowStep;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;

arch('no debugging helpers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('the default App namespace is not used')
    ->expect('App')
    ->not->toBeUsed();

arch('tenant-owned models use the fail-closed workspace scope')
    ->expect([
        WorkspaceMember::class,
        WorkspaceInvitation::class,
        JobRun::class,
        Site::class,
        SitePairing::class,
        SiteCredential::class,
        SiteHealthCheck::class,
        SiteTaxonomy::class,
        SiteContentItem::class,
        SitePublication::class,
        SiteMedia::class,
        SiteActivityLog::class,
        AiRequest::class,
        WorkflowRun::class,
        WorkflowStep::class,
        WorkflowEvent::class,
        SeoCampaign::class,
        ContentIdea::class,
        SearchConsoleConnection::class,
        TopicCluster::class,
        ContentGap::class,
        CompetitorFinding::class,
        CompetitorWebsite::class,
        Keyword::class,
        ResearchSource::class,
        InternalLinkSuggestion::class,
        ResearchSchedule::class,
        WebsiteProfile::class,
        ContentCalendarItem::class,
        CampaignSettings::class,
        KeywordMetric::class,
        SourceSnapshot::class,
        WebsiteKnowledge::class,
        ResearchRun::class,
        ContentIdeaKeyword::class,
        ResearchNotification::class,
        ResearchUsageCounter::class,
    ])
    ->toUseTrait(BelongsToWorkspace::class);

arch('kernel does not depend on module controllers')
    ->expect('SiteXPost\Kernel')
    ->not->toUse('SiteXPost\Modules\Workspace\Http');

arch('tenant API requests extend the base ApiRequest')
    ->expect(['SiteXPost\Modules\Workspace\Http\Requests', 'SiteXPost\Modules\Sites\Http\Requests', 'SiteXPost\Modules\AiGateway\Http\Requests', 'SiteXPost\Modules\Research\Http\Requests'])
    ->toExtend(ApiRequest::class);

arch('research reads WordPress data only through the Sites contract')
    ->expect('SiteXPost\Modules\Research')
    ->not->toUse(['SiteXPost\Modules\Sites\Domain', 'SiteXPost\Modules\Sites\Connector', 'SiteXPost\Modules\AiGateway\Providers']);

arch('the WordPress connector never touches AI provider credentials')
    ->expect('SiteXPost\Modules\Sites')
    ->not->toUse(['SiteXPost\Modules\AiGateway', 'SiteXPost\Modules\Providers']);

arch('the workflow engine is generic (no dependency on AI or connector code)')
    ->expect('SiteXPost\Modules\Workflows')
    ->not->toUse(['SiteXPost\Modules\AiGateway', 'SiteXPost\Modules\Sites']);

arch('only the AI Gateway talks to AI provider adapters')
    ->expect('SiteXPost\Modules\AiGateway\Providers')
    ->toOnlyBeUsedIn('SiteXPost\Modules\AiGateway');

arch('strict types everywhere in app')
    ->expect('SiteXPost')
    ->toUseStrictTypes();

arch('AI Writer tenant models use the fail-closed workspace scope')
    ->expect('SiteXPost\\Modules\\Writer\\Domain')
    ->classes()
    ->toUseTrait(BelongsToWorkspace::class)
    ->ignoring('SiteXPost\\Modules\\Writer\\Domain\\WriterSetting');

arch('AI Writer requests extend the base ApiRequest')
    ->expect('SiteXPost\\Modules\\Writer\\Http\\Requests')
    ->toExtend(ApiRequest::class);

arch('AI Writer reads planner / WordPress data only through contracts and never publishes')
    ->expect('SiteXPost\\Modules\\Writer')
    ->not->toUse(['SiteXPost\\Modules\\Research\\Domain', 'SiteXPost\\Modules\\Sites\\Domain', 'SiteXPost\\Modules\\Sites\\Connector', 'SiteXPost\\Modules\\AiGateway\\Providers']);

arch('Phase 6 tenant models use the fail-closed workspace scope')
    ->expect(['SiteXPost\\Modules\\Media\\Domain', 'SiteXPost\\Modules\\Seo\\Domain', 'SiteXPost\\Modules\\Publishing\\Domain'])
    ->classes()
    ->toUseTrait(BelongsToWorkspace::class)
    ->ignoring(['SiteXPost\\Modules\\Media\\Domain\\MediaLimit', 'SiteXPost\\Modules\\Publishing\\Domain\\PublishingLimit']);

arch('Phase 6 requests extend the base ApiRequest')
    ->expect(['SiteXPost\\Modules\\Media\\Http\\Requests', 'SiteXPost\\Modules\\Seo\\Http\\Requests', 'SiteXPost\\Modules\\Publishing\\Http\\Requests'])
    ->toExtend(ApiRequest::class);

arch('Phase 6 modules reach articles, sites and the planner only through contracts')
    ->expect(['SiteXPost\\Modules\\Media', 'SiteXPost\\Modules\\Seo', 'SiteXPost\\Modules\\Publishing'])
    ->not->toUse(['SiteXPost\\Modules\\Writer\\Domain', 'SiteXPost\\Modules\\Sites\\Domain', 'SiteXPost\\Modules\\Research\\Domain', 'SiteXPost\\Modules\\AiGateway\\Providers',
        'SiteXPost\\Modules\\Sites\\Actions', 'SiteXPost\\Modules\\Writer\\Actions']);

arch('only the Publishing module publishes to WordPress (Seo never does)')
    ->expect('SiteXPost\\Modules\\Seo')
    ->not->toUse(['SiteXPost\\Modules\\Sites\\Contracts\\WordPressGateway', 'SiteXPost\\Modules\\Publishing']);

arch('billing tenant models use the fail-closed workspace scope')
    ->expect([
        Subscription::class,
        SubscriptionEvent::class,
        SubscriptionChange::class,
        CreditWallet::class,
        CreditGrant::class,
        CreditReservation::class,
        CreditLedgerEntry::class,
        CreditAdjustment::class,
        UsageMeter::class,
        UsageEvent::class,
        PaymentOrder::class,
        Refund::class,
        BillingProfile::class,
        BillingDocument::class,
        BillingNotification::class,
    ])
    ->toUseTrait(BelongsToWorkspace::class);

arch('billing API requests extend the base ApiRequest (workspace_id is never accepted)')
    ->expect('SiteXPost\Modules\Billing\Http\Requests')
    ->toExtend(ApiRequest::class);

arch('only the Billing module knows billing internals; others use its contracts')
    ->expect(['SiteXPost\Modules\Billing\Credits', 'SiteXPost\Modules\Billing\Payments', 'SiteXPost\Modules\Billing\Subscriptions', 'SiteXPost\Modules\Billing\Entitlements'])
    ->toOnlyBeUsedIn(['SiteXPost\Modules\Billing', 'Database\Seeders']); // + the dev-only demo seeder

arch('Phase 8 tenant models use the fail-closed workspace scope')
    ->expect(['SiteXPost\\Modules\\Analytics\\Domain', 'SiteXPost\\Modules\\Agent\\Domain'])
    ->classes()
    ->toUseTrait(BelongsToWorkspace::class)
    ->ignoring('SiteXPost\\Modules\\Agent\\Domain\\AgentPolicy'); // platform-wide policy, Super Admin only

arch('Phase 8 requests extend the base ApiRequest (workspace_id is never accepted)')
    ->expect(['SiteXPost\\Modules\\Analytics\\Http\\Requests', 'SiteXPost\\Modules\\Agent\\Http\\Requests'])
    ->toExtend(ApiRequest::class);

arch('Phase 8 modules reach other modules only through contracts')
    ->expect(['SiteXPost\\Modules\\Analytics', 'SiteXPost\\Modules\\Agent'])
    ->not->toUse(['SiteXPost\\Modules\\Writer\\Domain', 'SiteXPost\\Modules\\Writer\\Actions', 'SiteXPost\\Modules\\Sites\\Domain', 'SiteXPost\\Modules\\Sites\\Actions',
        'SiteXPost\\Modules\\Sites\\Connector', 'SiteXPost\\Modules\\Sites\\Contracts\\WordPressGateway', 'SiteXPost\\Modules\\Research\\Domain',
        'SiteXPost\\Modules\\Publishing\\Domain', 'SiteXPost\\Modules\\Publishing\\Actions', 'SiteXPost\\Modules\\Seo\\Domain', 'SiteXPost\\Modules\\Seo\\Actions',
        'SiteXPost\\Modules\\Billing\\Credits', 'SiteXPost\\Modules\\Billing\\Payments', 'SiteXPost\\Modules\\Billing\\Subscriptions', 'SiteXPost\\Modules\\AiGateway\\Providers']);

arch('the agent uses Analytics only through its contract and never touches the database directly')
    ->expect('SiteXPost\\Modules\\Agent')
    ->not->toUse(['SiteXPost\\Modules\\Analytics\\Domain', 'SiteXPost\\Modules\\Analytics\\Actions', 'SiteXPost\\Modules\\Analytics\\Engine',
        'SiteXPost\\Modules\\Analytics\\Support', 'Illuminate\\Support\\Facades\\Process', 'shell_exec', 'exec', 'proc_open', 'passthru', 'system'])
    ->ignoring('SiteXPost\\Modules\\Agent\\Console');

arch('agent tools are executed only by the orchestrator')
    ->expect('SiteXPost\\Modules\\Agent\\Tools\\ToolExecutor')
    ->toOnlyBeUsedIn(['SiteXPost\\Modules\\Agent\\Engine']);

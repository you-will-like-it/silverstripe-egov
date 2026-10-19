<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Controller;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\Validation\RequiredFieldsValidator;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\View\Requirements;
use YouWillLikeIT\EGov\Enum\RequestStatus;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Procedure\ProcedureRegistry;
use YouWillLikeIT\EGov\Service\IdentifierGenerator;
use YouWillLikeIT\EGov\Tenant\TenantConfig;

/**
 * Status lookup: kod predmeta + JMBG/ID.
 *
 * JMBG alone is not a secret (it is printed on many documents and is partly
 * derivable from the date of birth), so the unguessable tracking code is the
 * second factor. The route is wrapped in RateLimitMiddleware (_config/routes.yml)
 * and the response never reveals which of the two values was wrong.
 */
class StatusController extends Controller
{
    private static array $allowed_actions = ['LookupForm'];

    protected function init(): void
    {
        parent::init();
        Requirements::css('youwilllikeit/silverstripe-egov: client/dist/egov.css');
        $this->getResponse()->addHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->getResponse()->addHeader('Cache-Control', 'private, no-store');
    }

    public function Link($action = null): string
    {
        return Controller::join_links(ProcedureRegistry::singleton()->portalBaseLink(), 'status', $action);
    }

    public function index(HTTPRequest $request): DBHTMLText
    {
        return $this->renderStatus(['Form' => $this->LookupForm()]);
    }

    public function LookupForm(): Form
    {
        $form = Form::create(
            $this,
            'LookupForm',
            FieldList::create(
                TextField::create('TrackingCode', 'Kod predmeta')
                    ->setAttribute('placeholder', TenantConfig::singleton()->trackingPrefix() . '-XXXXXXXX')
                    ->setAttribute('autocomplete', 'off')
                    ->setMaxLength(16),
                TextField::create('IdentificationNumber', 'JMBG / ID broj podnosioca')
                    ->setAttribute('inputmode', 'numeric')
                    ->setAttribute('autocomplete', 'off')
                    ->setMaxLength(13),
            ),
            FieldList::create(FormAction::create('doLookup', 'Provjeri status')->setUseButtonTag(true)->addExtraClass('egov-btn egov-btn--primary')),
            RequiredFieldsValidator::create(['TrackingCode', 'IdentificationNumber'])
        );
        $form->addExtraClass('egov-form');
        return $form;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doLookup(array $data, Form $form): DBHTMLText
    {
        $code = IdentifierGenerator::normalizeTrackingCode((string) ($data['TrackingCode'] ?? ''));
        $id = (string) preg_replace('/\D+/', '', (string) ($data['IdentificationNumber'] ?? ''));

        $case = null;
        if ($code !== '' && strlen($id) === 13) {
            $candidate = ApplicationRequest::get()->filter('TrackingCode', $code)->first();
            if ($candidate instanceof ApplicationRequest
                && $candidate->getStatusEnum() !== RequestStatus::Draft
                && $candidate->matchesIdentification($id)
            ) {
                $case = $candidate;
            }
        }

        // constant-ish response time against timing probes
        usleep(random_int(150_000, 350_000));

        $fresh = $this->LookupForm();
        if (!$case) {
            return $this->renderStatus([
                'Form' => $fresh,
                'NotFound' => true,
            ]);
        }

        $status = $case->getStatusEnum();
        return $this->renderStatus([
            'Form' => $fresh,
            'Case' => $case,
            'StatusLabel' => $status->label(),
            'StatusTone' => $status->tone(),
            'IsIncomplete' => $status->isSupplementRound(),
            'IsClosedNegative' => in_array($status, [RequestStatus::Rejected, RequestStatus::Dismissed], true),
            'IsAwaitingPayment' => $status === RequestStatus::AwaitingPayment,
            'IsReadyForPickup' => $status === RequestStatus::ReadyForPickup,
            'History' => \SilverStripe\Model\List\ArrayList::create(array_values(array_filter(
                $case->StatusChanges()->sort('Created', 'ASC')->toArray(),
                static fn ($c): bool => $c->FromStatus !== $c->ToStatus
            ))),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderStatus(array $data): DBHTMLText
    {
        $data = array_merge(TenantConfig::singleton()->toTemplateData(), [
            'Title' => 'Status zahtjeva',
            'PortalLink' => ProcedureRegistry::singleton()->portalBaseLink(),
            'StatusPageLink' => $this->Link(),
        ], $data);
        $content = $this->customise($data)->renderWith('YouWillLikeIT\\EGov\\Portal\\StatusPage');
        return $this->customise(array_merge($data, ['Content' => $content]))
            ->renderWith('YouWillLikeIT\\EGov\\Portal\\PortalPage');
    }
}

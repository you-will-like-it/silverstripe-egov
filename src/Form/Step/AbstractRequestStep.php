<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Form\Step;

use LogicException;
use SilverStripe\MultiForm\Models\MultiFormStep;
use YouWillLikeIT\EGov\Form\RequestMultiForm;
use YouWillLikeIT\EGov\Model\ApplicationRequest;
use YouWillLikeIT\EGov\Model\ProcedureDefinition;

/**
 * Common base for portal steps (silverstripe/multiform, SS6 branch / fork).
 */
class AbstractRequestStep extends MultiFormStep
{
    private static string $table_name = 'EGov_RequestStep';

    public function getProcedure(): ProcedureDefinition
    {
        $form = $this->getForm();
        if (!$form instanceof RequestMultiForm) {
            throw new LogicException('Korak mora biti dio RequestMultiForm.');
        }
        return $form->getProcedure();
    }

    /**
     * Unsaved instance of the procedure's request class – used to obtain
     * procedure specific fields without persisting anything.
     */
    public function getRequestPrototype(): ApplicationRequest
    {
        $class = $this->getProcedure()->getRequestClassName();
        /** @var ApplicationRequest $prototype */
        $prototype = $class::create();
        $prototype->ProcedureID = $this->getProcedure()->ID;
        $prototype->RegistryCode = (string) $this->getProcedure()->Code;
        return $prototype;
    }

    public function getPrevText()
    {
        return 'Nazad';
    }

    public function getNextText()
    {
        return 'Dalje';
    }

    public function getSubmitText()
    {
        return 'Kreiraj zahtjev i nastavi na priloge';
    }
}

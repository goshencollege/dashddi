<?php

namespace App\Tests\Functional\Controller;

use App\Entity\UserPreference;
use App\Tests\Functional\AppWebTestCase;

class UserPreferenceControllerTest extends AppWebTestCase
{
    public function testSetHostCollapsedSectionsAddsSectionWhenCollapsed(): void
    {
        $data = $this->apiRequest('POST', '/api/preference/host-collapsed-sections', [
            'section'   => 'ssh-host-keys',
            'collapsed' => true,
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(['section' => 'ssh-host-keys', 'collapsed' => true], $data);

        $pref = $this->em->getRepository(UserPreference::class)->findByIdentifier('test@example.com');
        $this->assertNotNull($pref);
        $this->assertSame(['ssh-host-keys'], $pref->getHostCollapsedSections());
    }

    public function testSetHostCollapsedSectionsRemovesSectionWhenExpanded(): void
    {
        $pref = new UserPreference('test@example.com');
        $pref->setHostCollapsedSections(['interfaces', 'ssh-host-keys']);
        $this->em->persist($pref);
        $this->em->flush();

        $data = $this->apiRequest('POST', '/api/preference/host-collapsed-sections', [
            'section'   => 'interfaces',
            'collapsed' => false,
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(['section' => 'interfaces', 'collapsed' => false], $data);

        $this->em->refresh($pref);
        $this->assertSame(['ssh-host-keys'], array_values($pref->getHostCollapsedSections()));
    }

    public function testSetHostCollapsedSectionsIsIdempotent(): void
    {
        $this->apiRequest('POST', '/api/preference/host-collapsed-sections', [
            'section'   => 'switch-ports',
            'collapsed' => true,
        ]);
        $this->apiRequest('POST', '/api/preference/host-collapsed-sections', [
            'section'   => 'switch-ports',
            'collapsed' => true,
        ]);

        $pref = $this->em->getRepository(UserPreference::class)->findByIdentifier('test@example.com');
        $this->assertSame(['switch-ports'], $pref->getHostCollapsedSections());
    }

    public function testSetHostCollapsedSectionsRejectsMissingSection(): void
    {
        $this->apiRequest('POST', '/api/preference/host-collapsed-sections', [
            'collapsed' => true,
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testSetHostCollapsedSectionsRejectsNonBooleanCollapsed(): void
    {
        $this->apiRequest('POST', '/api/preference/host-collapsed-sections', [
            'section'   => 'interfaces',
            'collapsed' => 'yes',
        ]);

        $this->assertResponseStatusCodeSame(400);
    }
}

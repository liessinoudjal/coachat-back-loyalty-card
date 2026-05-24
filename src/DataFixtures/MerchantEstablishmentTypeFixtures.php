<?php

namespace App\DataFixtures;

use App\Entity\MerchantEstablishmentType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class MerchantEstablishmentTypeFixtures extends Fixture
{
    /**
     * @var array<string, string>
     */
    private const TYPES = [
        'restaurant' => 'Restaurant',
        'restaurant_asiatique' => 'Restaurant asiatique',
        'restaurant_burger' => 'Restaurant burger',
        'restaurant_chicken' => 'Restaurant chicken',
        'restaurant_kebab' => 'Restaurant kebab',
        'fast_food' => 'Fast food',
        'brasserie' => 'Brasserie',
        'pizzeria' => 'Pizzeria',
        'grill' => 'Grill',
        'creperie' => 'Crêperie',
        'sandwicherie' => 'Sandwicherie',
        'cafe' => 'Café',
        'bar' => 'Bar / café',
        'food_truck' => 'Food truck',
        'metier_de_bouche' => 'Metier de bouche',
        'boulangerie' => 'Boulangerie',
        'boucherie' => 'Boucherie / Charcuterie',
        'boucherie_halal' => 'Boucherie halal',
        'boucherie_charcuterie_halal' => 'Boucherie / charcuterie halal',
        'charcuterie' => 'Charcuterie',
        'poissonnerie' => 'Poissonnerie',
        'patisserie' => 'Patisserie',
        'traiteur' => 'Traiteur',
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::TYPES as $code => $label) {
            $type = new MerchantEstablishmentType();
            $type->setCode($code);
            $type->setLabel($label);
            $manager->persist($type);
            $this->addReference(sprintf('merchant_establishment_type_%s', $code), $type);
        }

        $manager->flush();
    }
}

<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class AddToCartType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('product_id', HiddenType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Positive()],
            ])
            ->add('quantity', IntegerType::class, [
                'data' => 1,
                'attr' => ['min' => 1, 'max' => 10],
                'constraints' => [new Assert\NotBlank(), new Assert\Range(min: 1, max: 10)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'add_to_cart',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'add_to_cart';
    }
}

<?php
namespace App\Form;

use App\Entity\Event;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

class EventType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'constraints' => [new NotBlank()],
                'attr' => ['class' => 'form-control', 'placeholder' => 'Titre de l\'événement']
            ])
            ->add('description', TextareaType::class, [
                'constraints' => [new NotBlank()],
                'attr' => ['class' => 'form-control', 'rows' => 4, 'placeholder' => 'Description']
            ])
            ->add('date', DateTimeType::class, [
                'widget' => 'single_text',
                'constraints' => [new NotBlank()],
                'attr' => ['class' => 'form-control']
            ])
            ->add('location', TextType::class, [
                'constraints' => [new NotBlank()],
                'attr' => ['class' => 'form-control', 'placeholder' => 'Lieu de l\'événement']
            ])
            ->add('seats', IntegerType::class, [
                'constraints' => [new NotBlank(), new Positive()],
                'attr' => ['class' => 'form-control', 'placeholder' => 'Nombre de places']
            ])
            ->add('image', TextType::class, [
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'URL de l\'image (optionnel)']
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Event::class]);
    }
}
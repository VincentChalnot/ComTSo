<?php

namespace ComTSo\ForumBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class PhotoTopicType extends AbstractType {

	/**
	 * @param FormBuilderInterface $builder
	 * @param array $options
	 */
	public function buildForm(FormBuilderInterface $builder, array $options) {
		$builder
				->add('photo', 'photo', [
					'label' => false,
					'horizontal_input_wrapper_class' => 'col-lg-10 col-sm-offset-1',
					'browsable' => false,
				])
				->add('order', 'hidden');
	}

	/**
	 * @param OptionsResolverInterface $resolver
	 */
	public function setDefaultOptions(OptionsResolverInterface $resolver) {
		$resolver->setDefaults(array(
			'data_class' => 'ComTSo\ForumBundle\Entity\PhotoTopic',
		));
	}

	/**
	 * @return string
	 */
	public function getName() {
		return 'comtso_photo_topic';
	}

}

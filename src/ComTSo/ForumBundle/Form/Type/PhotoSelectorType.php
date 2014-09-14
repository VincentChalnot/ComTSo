<?php

namespace ComTSo\ForumBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class PhotoSelectorType extends AbstractType {

	/**
	 * @param OptionsResolverInterface $resolver
	 */
	public function setDefaultOptions(OptionsResolverInterface $resolver) {
		$resolver->setDefaults([
			'class' => 'ComTSo\ForumBundle\Entity\Photo',
			'browsable' => true,
		]);
	}
	
	public function buildView(\Symfony\Component\Form\FormView $view, \Symfony\Component\Form\FormInterface $form, array $options) {
		$view->vars['browsable'] = $options['browsable'];
	}
	
	public function getParent() {
		return 'entity';
	}

	/**
	 * @return string
	 */
	public function getName() {
		return 'photo';
	}

}

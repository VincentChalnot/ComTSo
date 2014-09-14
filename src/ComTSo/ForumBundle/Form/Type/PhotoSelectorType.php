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
			//'data_class' => 'ComTSo\ForumBundle\Entity\Photo',
		]);
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

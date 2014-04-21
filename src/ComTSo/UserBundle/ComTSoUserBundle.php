<?php

namespace ComTSo\UserBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class ComTSoUserBundle extends Bundle
{
	public function getParent() {
		return 'FOSUserBundle';
	}
}

<?php

namespace ComTSo\ForumBundle\Decoda;

use Decoda\Engine\AbstractEngine;
use Decoda\Exception\IoException;

/**
 * Renders tags by using PHP as template engine.
 */
class PhpEngine extends AbstractEngine
{
    /**
     * Renders the tag by using PHP templates.
     *
     * @param  array       $tag
     * @param  string      $content
     * @return string
     * @throws IoException
     */
    public function render(array $tag, $content)
    {
        $setup = $this->getFilter()->getTag($tag['tag']);

        foreach ($this->getPaths() as $path) {
            $template = sprintf('%s%s.php', $path, $setup['template']);

            if (file_exists($template)) {
                extract($tag['attributes'], EXTR_OVERWRITE);
                ob_start();

                include $template;

                return trim(ob_get_clean());
            }
        }

        throw new IoException(sprintf('Template file %s does not exist', $setup['template']));
    }

}

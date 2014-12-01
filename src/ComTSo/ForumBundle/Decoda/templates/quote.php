<blockquote>
    <p><?php echo $content; ?></p>
    <?php if (!empty($author)) : ?>
         <footer>
            <?php if (!empty($author)) : ?>
                <?php echo $this->getFilter()->message('quoteBy', [
                    'author' => htmlentities($author, ENT_NOQUOTES, 'UTF-8')
                ]); ?>
            <?php endif ?>
        </footer>
    <?php endif ?>
</blockquote>

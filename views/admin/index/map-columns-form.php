<form id="csvimport" method="post" action="">
<?php
    $colNames = $this->columnNames;
    $colExamples = $this->columnExamples;
?>
    <table id="column-mappings" class="simple" cellspacing="0" cellpadding="0">
    <thead>
    <tr>
        <th><?php __('Column'); ?></th>
        <th><?php echo __('Example from CSV File'); ?></th>
        <th><?php echo __('Map To Element'); ?></th>
        <th><?php echo __('Use HTML?'); ?></th>
        <th><?php echo __('Tags?'); ?></th>
        <th><?php echo __('File?'); ?></th>
    </tr>
    </thead>
    <tbody>
<?php for($i = 0; $i < count($colNames); $i++): ?>
        <tr>
        <td><strong><?php echo html_escape($colNames[$i]); ?></strong></td>
        <td>&quot;<?php echo html_escape($colExamples[$colNames[$i]]); ?>&quot;</td>
        <?php echo $this->form->getSubForm("row$i"); ?>
        </tr>
<?php endfor; ?>
    </tbody>
    </table>
    <fieldset>
    <?php echo $this->form->submit; ?>
    </fieldset>
</form>

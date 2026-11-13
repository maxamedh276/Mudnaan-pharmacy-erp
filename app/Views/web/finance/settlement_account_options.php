<option value="">Choose a payment account...</option>
<?php foreach (['cash' => 'Cash', 'bank' => 'Bank', 'mobile_money' => 'Mobile Money'] as $method => $label): ?>
    <?php $options = array_filter($settlementAccounts, static fn ($account) => $account['method'] === $method); ?>
    <?php if ($options): ?>
        <optgroup label="<?= esc($label, 'attr') ?>">
            <?php foreach ($options as $account): ?>
                <option value="<?= (int) $account['id'] ?>"><?= esc($account['code'] . ' — ' . $account['name']) ?></option>
            <?php endforeach; ?>
        </optgroup>
    <?php endif; ?>
<?php endforeach; ?>

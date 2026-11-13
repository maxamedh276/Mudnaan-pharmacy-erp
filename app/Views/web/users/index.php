<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="erp-page-title flex flex-wrap items-center gap-3">
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 tracking-tight">
                Staff User Management
            </h1>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-brand-700 border border-blue-200">
                Staff Directory
            </span>
        </div>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">
            Manage your store staff accounts, roles, access status, and login credentials.
        </p>
        <p class="text-xs text-slate-500 mt-1">Only Superadmins can manage Superadmin accounts, including other Superadmins.</p>
    </div>

    <div class="erp-page-actions flex flex-wrap items-center gap-2">
        <a href="<?= site_url('users/roles') ?>" 
           class="inline-flex items-center gap-1.5 px-3 py-2 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold rounded-lg border border-slate-200 shadow-2xs transition-colors">
            <i data-lucide="shield" class="w-4 h-4 text-slate-400"></i>
            <span>Roles & Permissions</span>
        </a>
        <button type="button" onclick="openAddUserModal()"
                class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 hover:bg-brand-700 active:scale-95 text-white text-xs font-semibold rounded-lg shadow-sm transition-all">
            <i data-lucide="user-plus" class="w-4 h-4"></i>
            <span>Add Staff Member</span>
        </button>
    </div>
</div>

<!-- Users Table Card -->
<div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-2xs">
    <div class="overflow-x-auto">
        <table id="usersTable" class="w-full text-left text-xs stripe hover">
            <thead class="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200">
                <tr>
                    <th class="py-3 px-3">Staff Member</th>
                    <th class="py-3 px-3">Contact Email & Phone</th>
                    <th class="py-3 px-3">Assigned Role</th>
                    <th class="py-3 px-3">Account Status</th>
                    <th class="py-3 px-3">Last Active</th>
                    <th class="py-3 px-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-700">
                <?php foreach ($users as $u): ?>
                    <?php 
                    $uInit = strtoupper(substr($u['name'] ?? 'U', 0, 1));
                    $isActive = (bool)($u['is_active'] ?? false);
                    $canManage = \App\Libraries\AuthContext::isSuperadmin() || !\App\Libraries\AdministrativeAccess::isSuperadminRole($u['role'] ?? '');
                    $isSelf = (int)$u['id'] === (int)\App\Libraries\AuthContext::userId();
                    ?>
                    <tr class="hover:bg-slate-50/80 transition-colors">
                        <td class="py-3 px-3">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-lg bg-blue-100 text-brand-700 flex items-center justify-center font-bold text-xs flex-shrink-0">
                                    <?= esc($uInit) ?>
                                </div>
                                <div>
                                    <span class="font-bold text-slate-900 block"><?= esc($u['name']) ?></span>
                                    <span class="text-[10px] text-slate-400">ID: #<?= (int)$u['id'] ?></span>
                                </div>
                            </div>
                        </td>
                        <td class="py-3 px-3">
                            <span class="text-slate-900 font-medium block"><?= esc($u['email'] ?: 'No email') ?></span>
                            <span class="text-[11px] text-slate-500 block"><?= esc($u['phone'] ?: 'No phone') ?></span>
                        </td>
                        <td class="py-3 px-3">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-800 border border-slate-200">
                                <?= esc($u['role'] ?? 'Unassigned') ?>
                            </span>
                        </td>
                        <td class="py-3 px-3">
                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $isActive ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-500 border border-slate-200' ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?= $isActive ? 'bg-emerald-500' : 'bg-slate-400' ?>"></span>
                                <span><?= $isActive ? 'Active' : 'Inactive' ?></span>
                            </span>
                        </td>
                        <td class="py-3 px-3 text-slate-500 whitespace-nowrap">
                            <?= esc($u['last_login_at'] ? date('M d, Y H:i', strtotime($u['last_login_at'])) : 'Never') ?>
                        </td>
                        <td class="py-3 px-3 text-right whitespace-nowrap">
                            <button type="button" <?= $canManage?'':'disabled title="Superadmin account"' ?> onclick="openEditUserModal(<?= htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8') ?>)"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-md transition-colors">
                                <i data-lucide="edit-2" class="w-3 h-3 text-slate-500"></i>
                                <span>Edit</span>
                            </button>
                            <button type="button" data-user-status-action <?= !$canManage ? 'disabled title="Only Superadmins can manage this account."' : ($isSelf && $isActive ? 'disabled title="You cannot deactivate your own account."' : '') ?> onclick="toggleUserStatus(<?= (int)$u['id'] ?>, <?= $isActive ? 'false' : 'true' ?>, this)"
                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 ml-1 text-[11px] font-semibold rounded-md border disabled:opacity-40 disabled:cursor-not-allowed focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 <?= $isActive ? 'bg-red-50 text-red-700 border-red-200 hover:bg-red-100' : 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100' ?>">
                                <i data-lucide="<?= $isActive ? 'user-x' : 'user-check' ?>" class="w-3 h-3" aria-hidden="true"></i>
                                <span><?= $isActive ? 'Deactivate' : 'Activate' ?></span>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal 1: Add User Modal -->
<div id="addUserModal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-lg overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900">Add New Staff Member</h3>
            <button onclick="closeAddUserModal()" class="text-slate-400 hover:text-slate-700">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form id="addUserForm" onsubmit="submitAddUser(event)" class="p-6 space-y-4 text-xs">
            <div>
                <label class="block font-semibold text-slate-700 mb-1">Full Name *</label>
                <input type="text" id="add_user_name" required placeholder="e.g. Maryan Nuur"
                       class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Email Address</label>
                    <input type="email" id="add_user_email" placeholder="maryan@demo.com"
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Phone Number</label>
                    <input type="text" id="add_user_phone" placeholder="+252 61..."
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 mb-1">Assign Role *</label>
                <select id="add_user_role_id" required
                        class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                    <option value="">Select a role...</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= (int)$r['id'] ?>"><?= esc($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 mb-1">Initial Password * (min 6 characters)</label>
                <input type="password" id="add_user_password" required minlength="6" placeholder="••••••••"
                       class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
            </div>

            <div class="pt-3 flex justify-end gap-2 border-t border-slate-100">
                <button type="button" onclick="closeAddUserModal()" class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-medium">Cancel</button>
                <button type="submit" id="add-user-btn" class="px-4 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-semibold shadow-sm">Save Staff Account</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Edit User Modal -->
<div id="editUserModal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-lg overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900">Edit Staff Account</h3>
            <button onclick="closeEditUserModal()" class="text-slate-400 hover:text-slate-700">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form id="editUserForm" onsubmit="submitEditUser(event)" class="p-6 space-y-4 text-xs">
            <input type="hidden" id="edit_user_id">

            <div>
                <label class="block font-semibold text-slate-700 mb-1">Full Name *</label>
                <input type="text" id="edit_user_name" required
                       class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Email Address</label>
                    <input type="email" id="edit_user_email"
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 mb-1">Phone Number</label>
                    <input type="text" id="edit_user_phone"
                           class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 mb-1">Role *</label>
                <select id="edit_user_role_id" required
                        class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= (int)$r['id'] ?>"><?= esc($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 mb-1">Change Password (leave blank to keep current)</label>
                <input type="password" id="edit_user_password" placeholder="New password..."
                       class="w-full px-3 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-brand-600 focus:ring-1 focus:ring-brand-600">
            </div>

            <div class="pt-3 flex justify-end gap-2 border-t border-slate-100">
                <button type="button" onclick="closeEditUserModal()" class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-medium">Cancel</button>
                <button type="submit" id="edit-user-btn" class="px-4 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-lg font-semibold shadow-sm">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    $(document).ready(function() {
        erp.initDataTable('#usersTable', {
            pageLength: 10,
            columnDefs: [{ orderable: false, targets: [5] }]
        });
    });

    function openAddUserModal() {
        document.getElementById('addUserForm').reset();
        document.getElementById('addUserModal').classList.remove('hidden');
    }

    function closeAddUserModal() {
        document.getElementById('addUserModal').classList.add('hidden');
    }

    async function submitAddUser(e) {
        e.preventDefault();
        const btn = document.getElementById('add-user-btn');
        btn.disabled = true;

        const payload = {
            name: document.getElementById('add_user_name').value.trim(),
            email: document.getElementById('add_user_email').value.trim(),
            phone: document.getElementById('add_user_phone').value.trim(),
            role_id: parseInt(document.getElementById('add_user_role_id').value, 10),
            password: document.getElementById('add_user_password').value
        };

        try {
            await erp.api('users', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            erp.toast('Staff member account created!');
            closeAddUserModal();
            setTimeout(() => window.location.reload(), 600);
        } catch (err) {
            // Handled
        } finally {
            btn.disabled = false;
        }
    }

    function openEditUserModal(user) {
        document.getElementById('edit_user_id').value = user.id;
        document.getElementById('edit_user_name').value = user.name || '';
        document.getElementById('edit_user_email').value = user.email || '';
        document.getElementById('edit_user_phone').value = user.phone || '';
        document.getElementById('edit_user_role_id').value = user.role_id || '';
        document.getElementById('edit_user_password').value = '';

        document.getElementById('editUserModal').classList.remove('hidden');
    }

    function closeEditUserModal() {
        document.getElementById('editUserModal').classList.add('hidden');
    }

    async function submitEditUser(e) {
        e.preventDefault();
        const userId = document.getElementById('edit_user_id').value;
        const btn = document.getElementById('edit-user-btn');
        btn.disabled = true;

        const payload = {
            name: document.getElementById('edit_user_name').value.trim(),
            email: document.getElementById('edit_user_email').value.trim(),
            phone: document.getElementById('edit_user_phone').value.trim(),
            role_id: parseInt(document.getElementById('edit_user_role_id').value, 10),
        };

        const pwd = document.getElementById('edit_user_password').value;
        if (pwd.length >= 6) {
            payload.password = pwd;
        }

        try {
            await erp.api('users/' + userId, {
                method: 'PUT',
                body: JSON.stringify(payload)
            });
            erp.toast('User updated successfully!');
            closeEditUserModal();
            setTimeout(() => window.location.reload(), 600);
        } catch (err) {
            // Handled
        } finally {
            btn.disabled = false;
        }
    }

    async function toggleUserStatus(userId, activate, button) {
        if (button?.disabled) return;
        if (button) button.disabled = true;
        let saved = false;
        try {
            if (!await erp.confirm(activate ? 'Allow this user to sign in again?' : 'This user will no longer be able to sign in. Continue?', {
                title: activate ? 'Activate user?' : 'Deactivate user?',
                confirmButtonText: activate ? 'Activate' : 'Deactivate',
                destructive: !activate
            })) return;
            button?.setAttribute('aria-busy', 'true');
            await erp.api('users/' + userId + '/active', {
                method: 'POST',
                body: JSON.stringify({ is_active: activate })
            });
            saved = true;
            erp.toast(activate ? 'Account activated' : 'Account deactivated');
            setTimeout(() => window.location.reload(), 500);
        } catch (err) {
            // Handled
        } finally {
            button?.removeAttribute('aria-busy');
            if (button && !saved) button.disabled = false;
        }
    }
</script>
<?= $this->endSection() ?>

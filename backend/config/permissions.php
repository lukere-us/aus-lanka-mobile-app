<?php

/*
| Roles and permissions for the first release (plan section 1.2).
| Permissions are enforced by the API (Gate + `permission:` middleware), never only hidden in UI.
| Organizer and gate-staff data scoping (own events only) is added by policies in the Events module.
*/

return [
    'permissions' => [
        'users.view' => 'View customer and staff accounts',
        'users.manage' => 'Suspend or reactivate accounts',
        'roles.assign' => 'Assign roles to users',
        'organizers.approve' => 'Approve or suspend organizers',
        'movies.manage' => 'Manage movies, media and rental packages',
        'events.manage' => 'Manage any event, venue and ticket setup',
        'events.approve' => 'Approve and publish events',
        'orders.view' => 'View orders and payments',
        'refunds.approve' => 'Approve refunds',
        'payouts.manage' => 'Manage commissions and organizer payouts',
        'reports.view' => 'View reports and exports',
        'settings.manage' => 'Change platform settings',
        'audit.view' => 'View the audit log',
        'organizer.events.manage' => 'Create and manage own events (organizer)',
        'organizer.attendees.view' => 'View attendees of own events (organizer)',
        'organizer.staff.manage' => 'Invite and manage own gate staff (organizer)',
        'scanner.checkin' => 'Scan and check in tickets at assigned events',
        'customer.purchase' => 'Buy rentals and tickets',
        'organizer.apply' => 'Apply to become an event organizer',
    ],

    // slug => [name, permissions]. super_admin is granted everything by Gate::before.
    'roles' => [
        'super_admin' => ['Super Admin', []],
        'administrator' => ['Administrator', [
            'users.view', 'users.manage', 'roles.assign', 'organizers.approve', 'movies.manage',
            'events.manage', 'events.approve', 'orders.view', 'reports.view', 'audit.view',
        ]],
        'finance_manager' => ['Finance Manager', ['orders.view', 'refunds.approve', 'payouts.manage', 'reports.view']],
        'content_manager' => ['Content Manager', ['movies.manage', 'reports.view']],
        'event_manager' => ['Event Manager', ['events.manage', 'events.approve', 'organizers.approve', 'reports.view']],
        'organizer' => ['Organizer', ['organizer.events.manage', 'organizer.attendees.view', 'organizer.staff.manage', 'scanner.checkin']],
        'gate_staff' => ['Gate Staff', ['scanner.checkin']],
        'customer' => ['Customer', ['customer.purchase', 'organizer.apply']],
    ],
];

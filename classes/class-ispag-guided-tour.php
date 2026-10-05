<?php
defined('ABSPATH') || exit;

/**
 * Guide pas à pas : explique les écrans à la première visite (« cliquez ici pour… »), avec possibilité de l'ignorer
 * et de le relancer plus tard (bouton « ? » en bas à droite).
 *
 *  - Un guide « bienvenue » (menu principal, notifications) puis un guide propre à chaque page (liste des projets, fiche projet,
 *    planning des livraisons, création de projet, tâches, contacts, entreprises…).
 *  - Chaque étape peut exiger un droit (cap) et un élément présent à l'écran : membre ISPAG, ingénieur ou client ne voient
 *    que les étapes qui concernent ce que leur compte peut voir ; une étape dont l'élément est absent est sautée.
 *  - État par utilisateur (meta ispag_tour_state) : « terminé » ou « ignoré » ; un guide n'est lancé automatiquement
 *    qu'une fois, mais peut être relancé à tout moment.
 *  - Ajouter / modifier un guide : tours() ci-dessous (filtre ispag_guided_tours pour les autres plugins).
 */
class ISPAG_Guided_Tour {

    const META   = 'ispag_tour_state';
    const NONCE  = 'ispag_tour';
    const ACTION = 'ispag_tour_state';

    public static function init() {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
        add_action('wp_ajax_' . self::ACTION, [self::class, 'ajax_state']);
    }

    // ------------------------------------------------------------------ page courante

    /** Clé de la page affichée (null si aucune n'a de guide). */
    public static function page_key() {
        if (!is_singular()) return null;
        $post = get_post();
        if (!$post) return null;
        $content  = (string) $post->post_content;
        $template = (string) get_page_template_slug($post);

        if ($template === 'page-project-detail-viewer.php' || has_shortcode($content, 'ispag_detail')) return 'project_detail';
        if (has_shortcode($content, 'ispag_creation_projet')) return 'project_new';
        if (has_shortcode($content, 'ispag_calendar_livraisons')) return 'deliveries';
        if (has_shortcode($content, 'ispag_projets')) return 'projects';
        if (has_shortcode($content, 'ispag_home')) return 'home';
        if (has_shortcode($content, 'ispag_standard_articles')) return 'standard_articles';
        if (has_shortcode($content, 'ispag_standard_article')) return 'std_article';
        if (has_shortcode($content, 'ispag_achat_detail')) return 'purchase_detail';
        if (has_shortcode($content, 'ispag_achats')) return 'purchases';
        if (has_shortcode($content, 'ispag_form_nouvelle_commande')) return 'purchase_new';
        if (has_shortcode($content, 'ispag_tanks_table')) return 'tanks';
        $map = [
            'page-task-dashboard.php'              => 'tasks',
            'page-list-contacts.php'               => 'contacts',
            'page-list-companies.php'              => 'companies',
            'page-contact-detail-responsive.php'   => 'contact_detail',
            'page-contact-detail.php'              => 'contact_detail',
            'page-company-detail.php'              => 'company_detail',
            'ispag-kanban-viewer.php'              => 'kanban',
            'page-template-table.php'              => 'templates',
            'page-user-profil.php'                 => 'profile',
        ];
        return $map[$template] ?? null;
    }

    // ------------------------------------------------------------------ définition des guides

    /**
     * id => ['page' => clé de page ('*' = toute page), 'title' => …, 'steps' => [ ['sel' => sélecteur CSS (liste possible, séparée par des virgules),
     *        'title' => …, 'text' => …, 'cap' => droit requis (optionnel), 'place' => top|bottom|left|right (optionnel)] ]]
     */
    public static function tours(): array {
        $tours = [
            'welcome' => [
                'page'  => '*',
                'title' => __('Welcome to ISPAG', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '', 'title' => __('Welcome!', 'creation-reservoir'),
                     'text' => __('This quick guide shows you where things are. You can skip it at any time and replay it later with the ? button at the bottom right.', 'creation-reservoir')],
                    ['sel' => '.menu-icon-projet > a, .menu-icon-projet', 'title' => __('Projects', 'creation-reservoir'),
                     'text' => __('Click here to open the list of projects: follow their progress, open one to see its articles, documents and deliveries.', 'creation-reservoir')],
                    ['sel' => '.menu-icon-offre > a, .menu-icon-offre', 'title' => __('Offers', 'creation-reservoir'),
                     'text' => __('Offers and selections that are not yet orders. An offer is turned into a project once it is accepted.', 'creation-reservoir')],
                    ['sel' => '.menu-icon-achat > a, .menu-icon-achat', 'title' => __('Purchases', 'creation-reservoir'), 'cap' => 'read_orders',
                     'text' => __('Purchase orders sent to suppliers, with their delivery dates and confirmations.', 'creation-reservoir')],
                    ['sel' => '.menu-icon-tache > a, .menu-icon-tache', 'title' => __('Tasks', 'creation-reservoir'),
                     'text' => __('Your tasks and reminders: what is late, what is due today and what comes next.', 'creation-reservoir')],
                    ['sel' => '.menu-icon-contact > a, .menu-icon-contact', 'title' => __('Contacts', 'creation-reservoir'), 'cap' => 'view_contact',
                     'text' => __('The people you work with. Open a contact to see its history, companies and projects.', 'creation-reservoir')],
                    ['sel' => '.menu-icon-company > a, .menu-icon-company', 'title' => __('Companies', 'creation-reservoir'), 'cap' => 'view_company',
                     'text' => __('Customers, suppliers and partners, with their contacts and projects.', 'creation-reservoir')],
                    ['sel' => '#ispag-notification-bell', 'title' => __('Notifications', 'creation-reservoir'), 'place' => 'bottom',
                     'text' => __('The bell shows what needs your attention: a delivery signed, an order confirmed, a task assigned to you. Click a notification to open the page concerned.', 'creation-reservoir')],
                    ['sel' => '#ispag-tour-launcher', 'title' => __('Need help?', 'creation-reservoir'), 'place' => 'left',
                     'text' => __('This button is always here. Use it to replay the guide of the page you are on, or to show all the guides again.', 'creation-reservoir')],
                ],
            ],
            'projects' => [
                'page'  => 'projects',
                'title' => __('The project list', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '#ispag-projects-search', 'title' => __('Search', 'creation-reservoir'),
                     'text' => __('Type a project name, number or customer, then press Enter or click Filter / Search.', 'creation-reservoir')],
                    ['sel' => '#ispag-projects-creator-filter', 'title' => __('Filter by creator', 'creation-reservoir'),
                     'text' => __('Show only the projects created by one person.', 'creation-reservoir')],
                    ['sel' => '.ispag-project-table', 'title' => __('Your projects', 'creation-reservoir'),
                     'text' => __('One line per project. Click a line to open the project. The list loads more projects as you scroll down.', 'creation-reservoir')],
                    ['sel' => '.ispag-next-step-badge', 'title' => __('Next step', 'creation-reservoir'),
                     'text' => __('Only the next step to complete is shown here. It updates by itself as articles are validated, ordered and delivered.', 'creation-reservoir')],
                ],
            ],
            'project_new' => [
                'page'  => 'project_new',
                'title' => __('Create a project', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '.pc-type-btn', 'title' => __('Project or quotation', 'creation-reservoir'),
                     'text' => __('Choose whether you create a project (an order) or a quotation (an offer that is not yet an order).', 'creation-reservoir')],
                    ['sel' => '#pc-copy, #select2-pc-copy-container', 'title' => __('Start from an existing project', 'creation-reservoir'),
                     'text' => __('To save time, search a similar project: its articles are copied into the new one.', 'creation-reservoir')],
                    ['sel' => '#pc-name', 'title' => __('Project name', 'creation-reservoir'),
                     'text' => __('A short name that you will recognize in the lists, for example the customer and the site.', 'creation-reservoir')],
                    ['sel' => '#pc-company, #select2-pc-company-container', 'title' => __('Company', 'creation-reservoir'),
                     'text' => __('The customer. Type to search; the company can also be changed later from the project page.', 'creation-reservoir')],
                    ['sel' => '#pc-contact, #select2-pc-contact-container', 'title' => __('Contact', 'creation-reservoir'),
                     'text' => __('The person to contact for this project. The first contact is the main contact.', 'creation-reservoir')],
                    ['sel' => '#pc-save', 'title' => __('Save', 'creation-reservoir'),
                     'text' => __('Creates the project and opens it, ready for you to add articles.', 'creation-reservoir')],
                ],
            ],
            'project_detail' => [
                'page'  => 'project_detail',
                'title' => __('The project page', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '.ispag-left-panel', 'title' => __('Project summary', 'creation-reservoir'), 'place' => 'right',
                     'text' => __('Name, amount, creation date, project manager and next step. Fields with a pencil can be edited by clicking them.', 'creation-reservoir')],
                    ['sel' => '.ispag-project-btn-card', 'title' => __('Actions', 'creation-reservoir'), 'cap' => 'manage_order', 'place' => 'right',
                     'text' => __('Add a product, go to purchases, replicate the project, or convert an offer into a project.', 'creation-reservoir')],
                    ['sel' => '.ispag-tabs-navigation', 'title' => __('Tabs', 'creation-reservoir'), 'place' => 'bottom',
                     'text' => __('Overview with the articles, Activities (notes, calls, e-mails), Details (delivery address, contacts), Follow-up and Documents.', 'creation-reservoir')],
                    ['sel' => '.ispag-articles-list', 'title' => __('Articles', 'creation-reservoir'), 'place' => 'top',
                     'text' => __('The products of the project. Use the buttons on the right of an article to see it, edit it or open more actions. Tick articles to act on several at once.', 'creation-reservoir')],
                    ['sel' => '#select-all-articles', 'title' => __('Select articles', 'creation-reservoir'), 'cap' => 'manage_order',
                     'text' => __('Tick the articles you want, then use the bar that appears: delivery note, change dates, send a purchase request…', 'creation-reservoir')],
                    ['sel' => '.ispag-company-card', 'title' => __('Company', 'creation-reservoir'), 'cap' => 'view_company', 'place' => 'left',
                     'text' => __('The customer of the project. With the + Add button you can change it; the bin removes it from the project.', 'creation-reservoir')],
                    ['sel' => '.ispag-contact-card', 'title' => __('Contacts', 'creation-reservoir'), 'cap' => 'view_contact', 'place' => 'left',
                     'text' => __('The people of the project. The first one is the main contact; click the star ☆ of another to make it the main contact. Use + Add to link more contacts.', 'creation-reservoir')],
                ],
            ],
            'purchases' => [
                'page'  => 'purchases',
                'title' => __('The purchase list', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '#ispag-achats-search', 'title' => __('Search', 'creation-reservoir'),
                     'text' => __('Find a purchase order by its reference, project or supplier.', 'creation-reservoir')],
                    ['sel' => '#ispag-achats-status-filter, #ispag-achats-fournisseur-filter, #ispag-achats-responsable-filter', 'title' => __('Filters', 'creation-reservoir'),
                     'text' => __('Narrow the list by status, supplier or the person in charge. The Reset button clears all filters.', 'creation-reservoir')],
                    ['sel' => '.ispag-project-table', 'title' => __('Purchase orders', 'creation-reservoir'),
                     'text' => __('One line per order with its dates. Click a line to open the order.', 'creation-reservoir')],
                    ['sel' => '.ispag-next-step-badge', 'title' => __('Status', 'creation-reservoir'),
                     'text' => __('Where the order stands: request for quotation, ordered, confirmed, materials received…', 'creation-reservoir')],
                ],
            ],
            'purchase_new' => [
                'page'  => 'purchase_new',
                'title' => __('Create a purchase request', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '#id_projet', 'title' => __('Customer project', 'creation-reservoir'),
                     'text' => __('Optional: link the purchase to a customer project to find it again from the project.', 'creation-reservoir')],
                    ['sel' => '#ref_commande', 'title' => __('Reference', 'creation-reservoir'),
                     'text' => __('Your own reference for this order.', 'creation-reservoir')],
                    ['sel' => '#id_fournisseur', 'title' => __('Supplier', 'creation-reservoir'),
                     'text' => __('The supplier who will receive the request. Mails and documents are written in the language of the supplier.', 'creation-reservoir')],
                    ['sel' => '#etat_commande', 'title' => __('Starting status', 'creation-reservoir'),
                     'text' => __('The status the order starts with, usually a request for quotation.', 'creation-reservoir')],
                ],
            ],
            'purchase_detail' => [
                'page'  => 'purchase_detail',
                'title' => __('The purchase order', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '.ispag-achat-header', 'title' => __('Order summary', 'creation-reservoir'), 'place' => 'right',
                     'text' => __('Reference, supplier and confirmation. Click the supplier name to open its company page.', 'creation-reservoir')],
                    ['sel' => '#achat-status-btn', 'title' => __('Status', 'creation-reservoir'), 'place' => 'right',
                     'text' => __('Change the status of the order as it moves forward. Some statuses send an e-mail to the supplier.', 'creation-reservoir')],
                    ['sel' => '.ispag-actions-collapsible', 'title' => __('Actions', 'creation-reservoir'), 'place' => 'right',
                     'text' => __('Go back to the project or the purchase list, add a product, or prepare the delivery note of the articles you ticked.', 'creation-reservoir')],
                    ['sel' => '.ispag-tabs-navigation', 'title' => __('Tabs', 'creation-reservoir'), 'place' => 'bottom',
                     'text' => __('Articles of the order, delivery details, follow-up and documents.', 'creation-reservoir')],
                    ['sel' => '.ispag-achat-articles-list', 'title' => __('Articles', 'creation-reservoir'), 'place' => 'top',
                     'text' => __('The ordered products with quantity, price and delivery status. Tick articles to act on several at once.', 'creation-reservoir')],
                    ['sel' => '.ispag-right-panel', 'title' => __('Supplier', 'creation-reservoir'), 'place' => 'left',
                     'text' => __('The supplier details and its contacts for this order.', 'creation-reservoir')],
                ],
            ],
            'standard_articles' => [
                'page'  => 'standard_articles',
                'title' => __('Standard articles', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '.ispag-std-chips', 'title' => __('Article types', 'creation-reservoir'),
                     'text' => __('Filter the catalogue by type of article: tanks, consumables, insulation…', 'creation-reservoir')],
                    ['sel' => '.ispag-std-toolbar', 'title' => __('Search', 'creation-reservoir'),
                     'text' => __('Search by reference or title, and filter by supplier or by articles without supplier.', 'creation-reservoir')],
                    ['sel' => '.ispag-std-table', 'title' => __('The catalogue', 'creation-reservoir'),
                     'text' => __('Click an article to open its sheet: prices, suppliers and documents.', 'creation-reservoir')],
                    ['sel' => '#ispag-std-new-toggle', 'title' => __('New article', 'creation-reservoir'),
                     'text' => __('Create a new standard article.', 'creation-reservoir')],
                    ['sel' => '.ispag-std-actions a', 'title' => __('Export', 'creation-reservoir'),
                     'text' => __('Download the catalogue as a CSV file.', 'creation-reservoir')],
                ],
            ],
            'std_article' => [
                'page'  => 'std_article',
                'title' => __('A standard article', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '.ispag-std-head', 'title' => __('Identity', 'creation-reservoir'),
                     'text' => __('Number, type, image and title. The title is edited directly in the field.', 'creation-reservoir')],
                    ['sel' => '.ispag-std-tabs', 'title' => __('Sales, purchasing, documents', 'creation-reservoir'),
                     'text' => __('Sales: selling price and description. Purchasing: suppliers and purchase prices. Documents: files attached to the article.', 'creation-reservoir')],
                    ['sel' => '.ispag-std-price-form', 'title' => __('Change a price', 'creation-reservoir'),
                     'text' => __('Enter the new price and the date from which it applies: the history of prices is kept.', 'creation-reservoir')],
                    ['sel' => '#ispag-std-delete', 'title' => __('Delete', 'creation-reservoir'),
                     'text' => __('Deletes the article from the catalogue. Articles already used in projects are not affected.', 'creation-reservoir')],
                ],
            ],
            'tanks' => [
                'page'  => 'tanks',
                'title' => __('The tank list', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '.ispag-tanks-container .ispag-toolbar', 'title' => __('Search and filters', 'creation-reservoir'),
                     'text' => __('Filter the tanks by type and search by project or article.', 'creation-reservoir')],
                    ['sel' => '.ispag-tanks-container .ispag-project-table', 'title' => __('Tanks', 'creation-reservoir'),
                     'text' => __('One line per tank with its project, date and design status. Click a line to open it.', 'creation-reservoir')],
                ],
            ],
            'kanban' => [
                'page'  => 'kanban',
                'title' => __('The deals board', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '#ispag-kanban-search', 'title' => __('Search', 'creation-reservoir'),
                     'text' => __('Find a deal by its name or company.', 'creation-reservoir')],
                    ['sel' => '#ispag-kanban-closing-date-filter, #ispag-kanban-create-date-filter, #ispag-kanban-owner-filter', 'title' => __('Filters', 'creation-reservoir'),
                     'text' => __('Filter by closing date, creation date or owner. Clear Filters shows everything again.', 'creation-reservoir')],
                    ['sel' => '.ispag-kanban-board', 'title' => __('The board', 'creation-reservoir'),
                     'text' => __('One column per stage. Drag a deal card to another column to change its stage.', 'creation-reservoir')],
                    ['sel' => '.ispag-board-controls a.ispag-btn', 'title' => __('Table view', 'creation-reservoir'),
                     'text' => __('Show the same deals as a table.', 'creation-reservoir')],
                ],
            ],
            'templates' => [
                'page'  => 'templates',
                'title' => __('Message templates', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '#tpl-search, #tpl-filter-owner', 'title' => __('Search and filter', 'creation-reservoir'),
                     'text' => __('Find a template by name and show only yours, the shared ones or those of someone else.', 'creation-reservoir')],
                    ['sel' => '#ispag-add-new-tpl', 'title' => __('Add a template', 'creation-reservoir'),
                     'text' => __('Create a reusable message for your e-mails, in the language you choose.', 'creation-reservoir')],
                    ['sel' => '#ispag-add-new-folder', 'title' => __('Folders', 'creation-reservoir'),
                     'text' => __('Organise your templates in folders.', 'creation-reservoir')],
                ],
            ],
            'profile' => [
                'page'  => 'profile',
                'title' => __('Your profile', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '#display_name', 'title' => __('Your information', 'creation-reservoir'),
                     'text' => __('The name shown to your colleagues and your e-mail address.', 'creation-reservoir')],
                    ['sel' => '#avatar_upload', 'title' => __('Your photo', 'creation-reservoir'),
                     'text' => __('Add a photo so that colleagues recognize you.', 'creation-reservoir')],
                    ['sel' => '#current_password', 'title' => __('Password', 'creation-reservoir'),
                     'text' => __('Change your password here: enter the current one, then the new one twice.', 'creation-reservoir')],
                    ['sel' => '.ispag-cards-mobile', 'title' => __('Notification preferences', 'creation-reservoir'),
                     'text' => __('Choose for each type of notification how you want to receive it: bell, e-mail or other channels.', 'creation-reservoir')],
                ],
            ],
            'deliveries' => [
                'page'  => 'deliveries',
                'title' => __('The delivery schedule', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '.ispag-calendar-nav-container', 'title' => __('Navigate', 'creation-reservoir'),
                     'text' => __('Go to the previous or next month, or come back to today.', 'creation-reservoir')],
                    ['sel' => '.ispag-cal-viewswitch', 'title' => __('Month or list', 'creation-reservoir'),
                     'text' => __('Switch between the calendar view and a list of the deliveries.', 'creation-reservoir')],
                    ['sel' => '.ispag-cal-search', 'title' => __('Search', 'creation-reservoir'),
                     'text' => __('Find a project in the schedule by typing a part of its name.', 'creation-reservoir')],
                    ['sel' => '.ispag-calendar-table, .ispag-calendar-container', 'title' => __('Deliveries', 'creation-reservoir'),
                     'text' => __('Each coloured entry is a delivery. Click it to open the project. The colour shows the type of product.', 'creation-reservoir')],
                ],
            ],
            'tasks' => [
                'page'  => 'tasks',
                'title' => __('Your tasks', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '.ispag-task-chips', 'title' => __('Urgency', 'creation-reservoir'),
                     'text' => __('Tasks are grouped by urgency: late, today, this week… Click a chip to filter.', 'creation-reservoir')],
                    ['sel' => '#taskSearch', 'title' => __('Search', 'creation-reservoir'),
                     'text' => __('Find a task by its title or the project it belongs to.', 'creation-reservoir')],
                    ['sel' => '#taskTypeFilter', 'title' => __('Type', 'creation-reservoir'),
                     'text' => __('Show only calls, e-mails, meetings or other kinds of task.', 'creation-reservoir')],
                    ['sel' => '.ispag-task-table, #the-list', 'title' => __('The list', 'creation-reservoir'),
                     'text' => __('Tick a task when it is done. You can also postpone it quickly from its line.', 'creation-reservoir')],
                ],
            ],
            'contacts' => [
                'page'  => 'contacts', 'cap' => 'view_contact',
                'title' => __('The contact list', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '.ispag-toolbar, .ispag-contact-filter-form', 'title' => __('Search and filters', 'creation-reservoir'),
                     'text' => __('Search by name, e-mail or company, and filter by status, owner or company.', 'creation-reservoir')],
                    ['sel' => '.ispag-contact-list-table', 'title' => __('Contacts', 'creation-reservoir'),
                     'text' => __('Click a contact to open its page. Tick several contacts to change them in bulk.', 'creation-reservoir')],
                ],
            ],
            'companies' => [
                'page'  => 'companies', 'cap' => 'view_company',
                'title' => __('The company list', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '.ispag-toolbar, .ispag-search-form', 'title' => __('Search', 'creation-reservoir'),
                     'text' => __('Search a company by name or city.', 'creation-reservoir')],
                    ['sel' => '.ispag-company-list-table', 'title' => __('Companies', 'creation-reservoir'),
                     'text' => __('Click a company to see its contacts, projects and history.', 'creation-reservoir')],
                ],
            ],
            'contact_detail' => [
                'page'  => 'contact_detail', 'cap' => 'view_contact',
                'title' => __('A contact page', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '.ispag-quick-actions', 'title' => __('Quick actions', 'creation-reservoir'),
                     'text' => __('Write a note, call, send an e-mail or schedule a meeting with this contact.', 'creation-reservoir')],
                    ['sel' => '.ispag-info-section, .ispag-contact-info-top', 'title' => __('Information', 'creation-reservoir'),
                     'text' => __('Click a field with a pencil to edit it: phone, e-mail, role, language…', 'creation-reservoir')],
                    ['sel' => '.ispag-nav-tabs', 'title' => __('History', 'creation-reservoir'),
                     'text' => __('Activities, documents and projects linked to the contact.', 'creation-reservoir')],
                ],
            ],
            'company_detail' => [
                'page'  => 'company_detail', 'cap' => 'view_company',
                'title' => __('A company page', 'creation-reservoir'),
                'steps' => [
                    ['sel' => '.ispag-header-card', 'title' => __('The company', 'creation-reservoir'),
                     'text' => __('Name, address and key information. Click a field with a pencil to edit it.', 'creation-reservoir')],
                    ['sel' => '.ispag-actions-bar', 'title' => __('Actions', 'creation-reservoir'),
                     'text' => __('Write a note, call, send an e-mail or schedule a meeting.', 'creation-reservoir')],
                    ['sel' => '.ispag-main-content', 'title' => __('History and projects', 'creation-reservoir'),
                     'text' => __('Contacts, projects and activities of this company.', 'creation-reservoir')],
                ],
            ],
        ];
        return (array) apply_filters('ispag_guided_tours', $tours);
    }

    // ------------------------------------------------------------------ état utilisateur

    public static function state(int $user_id): array {
        $s = get_user_meta($user_id, self::META, true);
        return is_array($s) ? $s : [];
    }

    public static function ajax_state() {
        if (!is_user_logged_in()) wp_send_json_error([], 403);
        check_ajax_referer(self::NONCE, 'nonce');
        $uid   = get_current_user_id();
        $tour  = sanitize_key($_POST['tour'] ?? '');
        $value = sanitize_key($_POST['value'] ?? '');
        $state = self::state($uid);

        if ($tour === '*' && $value === 'reset') {
            $state = [];                                   // « montrer à nouveau tous les guides »
        } elseif ($tour === '*' && $value === 'skipped') {
            foreach (array_keys(self::tours()) as $id) {   // « ignorer le guide » : tous les guides non terminés, sur toutes les pages
                if (empty($state[$id])) $state[$id] = ['s' => 'skipped', 't' => time()];
            }
        } elseif (isset(self::tours()[$tour]) && in_array($value, ['done', 'skipped', 'reset'], true)) {
            if ($value === 'reset') unset($state[$tour]); else $state[$tour] = ['s' => $value, 't' => time()];
        } else {
            wp_send_json_error([], 400);
        }
        update_user_meta($uid, self::META, $state);
        wp_send_json_success();
    }

    // ------------------------------------------------------------------ chargement

    public static function enqueue() {
        if (!is_user_logged_in() || is_admin()) return;
        $page = self::page_key();
        $user = wp_get_current_user();

        // Guides applicables à cette page, étapes filtrées par les droits de l'utilisateur
        $out = [];
        foreach (self::tours() as $id => $t) {
            if ($t['page'] !== '*' && $t['page'] !== $page) continue;
            if (!empty($t['cap']) && !user_can($user, $t['cap'])) continue;
            $steps = [];
            foreach ($t['steps'] as $s) {
                if (!empty($s['cap']) && !user_can($user, $s['cap'])) continue;
                $steps[] = ['sel' => (string) ($s['sel'] ?? ''), 'title' => (string) $s['title'], 'text' => (string) $s['text'], 'place' => (string) ($s['place'] ?? '')];
            }
            if ($steps) $out[] = ['id' => $id, 'title' => (string) $t['title'], 'page' => $t['page'] === '*' ? 'welcome' : 'page', 'steps' => $steps];
        }
        if (!$out) return;
        // « bienvenue » d'abord, puis le guide de la page
        usort($out, function ($a, $b) { return ($a['page'] === 'welcome' ? 0 : 1) <=> ($b['page'] === 'welcome' ? 0 : 1); });

        $dir  = dirname(__DIR__) . '/assets/';
        $url  = plugin_dir_url(__DIR__) . 'assets/';
        $ver  = (string) max((int) @filemtime($dir . 'js/guided-tour.js'), (int) @filemtime($dir . 'css/guided-tour.css'));
        wp_enqueue_style('ispag-guided-tour', $url . 'css/guided-tour.css', [], $ver);
        wp_enqueue_script('ispag-guided-tour', $url . 'js/guided-tour.js', [], $ver, true);
        wp_localize_script('ispag-guided-tour', 'ispagTour', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'action' => self::ACTION,
            'tours' => $out,
            'state' => (object) array_map(function ($x) { return is_array($x) ? ($x['s'] ?? '') : ''; }, self::state($user->ID)),
            'i18n'  => [
                'next'     => __('Next', 'creation-reservoir'),
                'back'     => __('Back', 'creation-reservoir'),
                'done'     => _x('Finish', 'guided tour', 'creation-reservoir'),
                'skip'     => __('Skip the guide', 'creation-reservoir'),
                'step'     => __('Step %1$d of %2$d', 'creation-reservoir'),
                'help'     => __('Guide', 'creation-reservoir'),
                'replay'   => __('Replay the guide of this page', 'creation-reservoir'),
                'welcome'  => __('Replay the welcome guide', 'creation-reservoir'),
                'resetAll' => __('Show all guides again', 'creation-reservoir'),
                'resetOk'  => __('The guides will be shown again as you visit the pages.', 'creation-reservoir'),
                'later'    => __('Guides ignored. You can show them again with the ? button.', 'creation-reservoir'),
                'close'    => __('Close', 'creation-reservoir'),
            ],
        ]);
    }
}

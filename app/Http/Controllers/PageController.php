<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\SEO\AlgorithmsSchema;
use App\SEO\OrganizationSchema;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RalphJSmit\Laravel\SEO\SchemaCollection;
use RalphJSmit\Laravel\SEO\Support\SEOData;

class PageController extends Controller
{
    public function home()
    {
        // Weekly rolling activity: sessions happening this week
        $sessionsThisWeek = Game::query()
            ->public()
            ->where('date_time', '>=', now()->startOfWeek())
            ->where('date_time', '<=', now()->endOfWeek())
            ->count();

        // Active campaigns (non-completed, public)
        $activeCampaigns = Campaign::query()
            ->where('visibility', 'public')
            ->where('status', '!=', 'completed')
            ->count();

        // Total participants across this week's public scheduled sessions
        $peopleThisWeek = Game::query()
            ->where('games.visibility', 'public')
            ->where('games.status', 'scheduled')
            ->where('games.date_time', '>=', now()->startOfWeek())
            ->where('games.date_time', '<=', now()->endOfWeek())
            ->join('game_participants', 'games.id', '=', 'game_participants.game_id')
            ->count();

        $schema = SchemaCollection::initialize();
        $schema->markup[OrganizationSchema::class][] = fn (OrganizationSchema $s) => $s;

        seo(new SEOData(
            title: __('pages.seo_title_home'),
            description: __('pages.seo_description_home'),
            schema: $schema,
        ));

        return view('pages.home', compact('sessionsThisWeek', 'activeCampaigns', 'peopleThisWeek'));
    }

    public function about()
    {
        seo(new SEOData(
            title: __('pages.seo_title_about'),
            description: __('pages.seo_description_about'),
        ));

        return view('pages.about');
    }

    public function howItWorks()
    {
        seo(new SEOData(
            title: __('pages.seo_title_how_it_works'),
            description: __('pages.seo_description_how_it_works'),
        ));

        return view('pages.how-it-works');
    }

    public function forOrganizers()
    {
        $organizerCount = User::has('ownedGames')->count();
        $displayCount = $organizerCount >= 10 ? $organizerCount : 50;

        seo(new SEOData(
            title: __('pages.seo_title_for_organizers'),
            description: __('pages.seo_description_for_organizers'),
        ));

        return view('pages.for-organizers', compact('displayCount'));
    }

    public function contact()
    {
        seo(new SEOData(
            title: __('pages.seo_title_contact'),
            description: __('pages.seo_description_contact'),
        ));

        return view('pages.contact');
    }

    public function ourPledge()
    {
        seo(new SEOData(
            title: __('pages.seo_title_our_pledge'),
            description: __('pages.seo_description_our_pledge'),
        ));

        return view('pages.pledge');
    }

    public function algorithms()
    {
        $schema = SchemaCollection::initialize();
        $schema->markup[AlgorithmsSchema::class][] = fn (AlgorithmsSchema $s) => $s;

        seo(new SEOData(
            title: __('pages.seo_title_pledge_algorithms'),
            description: __('pages.seo_description_pledge_algorithms'),
            schema: $schema,
        ));

        return view('pages.pledge-algorithms');
    }

    public function safetyTools()
    {
        $tools = \App\Enums\SafetyTool::cases();
        $categories = \App\Enums\SafetyToolCategory::cases();

        seo(new SEOData(
            title: __('safety.seo_title_safety_tools'),
            description: __('safety.seo_description_safety_tools'),
        ));

        return view('pages.safety-tools', compact('tools', 'categories'));
    }

    public function submitContact(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'in:general,account_recovery'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $category = $validated['category'] ?? 'general';
        $isAccountRecovery = $category === 'account_recovery';

        // Account recovery → Account Support department; general → Contact department
        $departmentName = $isAccountRecovery ? 'Account Support' : 'Contact';
        $department = Department::where('name', $departmentName)->first();
        if (! $department) {
            return redirect()
                ->route('contact')
                ->withErrors(['message' => __('support.error_unavailable')]);
        }

        $baseTicketData = [
            'subject' => $validated['subject'] ?? ($isAccountRecovery ? 'Account Recovery Request' : 'General Inquiry'),
            'description' => $validated['message'],
            'priority' => 'medium',
            'department_id' => $department->id,
        ];
        if ($isAccountRecovery) {
            $baseTicketData['ticket_type'] = 'account_recovery';
        }

        if (auth()->check()) {
            /** @var User $user */
            $user = auth()->user();
            Ticket::create($baseTicketData + [
                'requester_type' => User::class,
                'requester_id' => $user->id,
            ]);
        } else {
            Ticket::create($baseTicketData + [
                'guest_name' => $validated['name'],
                'guest_email' => $validated['email'],
                'guest_token' => Str::uuid()->toString(),
            ]);
        }

        return redirect()->route('contact')->with('success', __('common.content_thank_you_for_your_message'));
    }
}

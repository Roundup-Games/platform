<?php

namespace App\Http\Controllers;

use App\Mail\ContactFormSubmitted;
use App\Models\Campaign;
use App\Models\ContactMessage;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
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

        return view('pages.home', compact('sessionsThisWeek', 'activeCampaigns', 'peopleThisWeek'));
    }

    public function about()
    {
        return redirect()->route('how-it-works', app()->getLocale(), 301);
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
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $contactMessage = ContactMessage::create($validated);

        // Send notification email (queued)
        $adminEmail = config('mail.from.address');
        if ($adminEmail) {
            Mail::to($adminEmail)->send(new ContactFormSubmitted($contactMessage));
        }

        return redirect()->route('contact')->with('success', __('common.content_thank_you_for_your_message'));
    }
}

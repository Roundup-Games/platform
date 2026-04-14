<?php

namespace App\Http\Controllers;

use App\Mail\ContactFormSubmitted;
use App\Models\ContactMessage;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class PageController extends Controller
{
    public function home()
    {
        $upcomingEvents = Event::query()
            ->public()
            ->upcoming()
            ->take(6)
            ->get();

        $featuredEvents = Event::query()
            ->public()
            ->featured()
            ->upcoming()
            ->take(3)
            ->get();

        $teamCount = Team::count();
        $registrationCount = EventRegistration::count();

        return view('pages.home', compact('upcomingEvents', 'featuredEvents', 'teamCount', 'registrationCount'));
    }

    public function about()
    {
        return view('pages.about');
    }

    public function contact()
    {
        return view('pages.contact');
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

        return redirect()->route('contact')->with('success', __('Thank you for your message! We\'ll get back to you soon.'));
    }
}

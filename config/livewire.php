<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Livewire Frontend Assets
    |--------------------------------------------------------------------------
    |
    | Asset injection is OFF: the Livewire + Alpine runtime ships as a Vite
    | bundle (resources/js/app.js imports the vendor ESM build — CWV 2026-11,
    | commit 30dc5171), and both layouts render @livewireScriptConfig in
    | <head> to configure that bundle. With the default `true`, Livewire
    | auto-injects its synchronous livewire.min.js into every response that
    | rendered a component — which, next to the bundled runtime, double-boots
    | Alpine on every authenticated page: the second plugin registration
    | dies with "can't redefine non-configurable property '$persist'" and
    | every Alpine/Livewire surface stays blank (live incident 2026-11-20:
    | authed pages rendered tabs but no contents until a lucky refresh;
    | reproducible in a private window). Pages without components were
    | never injected, which is why the guest surface looked healthy.
    |
    | If you ever revert to tag-based loading, remove the bundle import
    | from app.js, drop the @livewireScriptConfig blocks from both
    | layouts, and set this back to true — never run both at once.
    */

    'inject_assets' => false,

];

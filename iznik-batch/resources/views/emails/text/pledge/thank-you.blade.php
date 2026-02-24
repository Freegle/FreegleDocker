Hi {{ $firstName }},

At the start of 2025 you did something brilliant - you took the Freegle Pledge and promised to give away at least one item every month.

@if($monthsFreegled >= 10)
Wow - {{ $monthsFreegled }} months! You absolutely smashed it. You're a freegling superstar and your commitment throughout the year has been incredible.
@elseif($monthsFreegled >= 5)
{{ $monthsFreegled }} months of freegling - that's fantastic! Life gets busy but you stuck with it and made a real difference.
@elseif($monthsFreegled >= 1)
You freegled in {{ $monthsFreegled }} {{ $monthsFreegled === 1 ? 'month' : 'months' }} - and that matters. Even one month of giving things away makes a difference.
@else
Just by signing up you showed you care about reducing waste and helping your community.
@endif

Together, our pledgers have:
- Given thousands of items a new home
- Kept tonnes of stuff out of landfill
- Helped neighbours across the country
- Reduced waste, one freegle at a time

The Freegle Pledge campaign is wrapping up, but freegling never stops! Whenever you've got something you no longer need, pop it on Freegle and make someone's day.

Thank you for being part of something good.

Keep freegling: {{ $userSite }}

With huge thanks from all of us at Freegle,
The Freegle Volunteers

---
To change your notification settings: {{ $settingsUrl }}

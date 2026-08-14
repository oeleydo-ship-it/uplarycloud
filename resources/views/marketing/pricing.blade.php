<x-marketing-layout :page="$page">
    <div class="mkt-wrap mkt-page" x-data="{ cycle: 'monthly' }">
        <span class="mkt-kicker">{{ $page->hero_kicker }}</span>
        <h1 class="mkt-title">{{ $page->hero_title }}</h1>
        <p class="mkt-lead">{{ $page->hero_description }}</p>
        @if($page->body_html)<section class="mkt-managed-content">{!! $page->body_html !!}</section>@endif

        <div class="mkt-cycle" role="group" aria-label="Billing cycle">
            <span :class="cycle === 'monthly' && 'is-on'">Monthly</span>
            <button type="button" @click="cycle = cycle === 'monthly' ? 'yearly' : 'monthly'" :class="cycle === 'yearly' && 'is-yearly'" :aria-pressed="cycle === 'yearly'">
                <i></i>
            </button>
            <span :class="cycle === 'yearly' && 'is-on'">Yearly <em>Save 20%</em></span>
        </div>

        <div class="mkt-prices">
            @foreach($plans as $plan)
                @php
                    $features = is_array($plan->features ?? null) ? $plan->features : [];
                    if ($features === [] && is_array($plan->limits ?? null)) {
                        foreach (['servers' => 'servers', 'applications' => 'applications', 'team_members' => 'team members'] as $key => $label) {
                            $value = $plan->limits[$key] ?? null;
                            $features[] = ($value === null || $value === '' ? 'Unlimited' : $value).' '.$label;
                        }
                    }
                @endphp
                <article class="mkt-price {{ ($plan->featured ?? false) ? 'is-featured' : '' }}">
                    @if($plan->featured ?? false)
                        <span class="mkt-price-label">Most popular</span>
                    @endif
                    <h3>{{ $plan->name }}</h3>
                    <p>{{ $plan->description }}</p>
                    <div class="mkt-amount">
                        <strong x-text="{{ (int) $plan->monthly_price }} === 0 && {{ (int) $plan->yearly_price }} === 0 ? 'Free' : '$' + ((cycle === 'yearly' ? {{ (int) $plan->yearly_price }} : {{ (int) $plan->monthly_price }}) / 100)"></strong>
                        <span x-show="{{ (int) $plan->monthly_price }} > 0 || {{ (int) $plan->yearly_price }} > 0" x-text="cycle === 'yearly' ? '/ year' : '/ month'"></span>
                    </div>
                    <ul>
                        @forelse($features as $feature)
                            <li><i data-lucide="check"></i>{{ $feature }}</li>
                        @empty
                            <li><i data-lucide="check"></i>Core platform access</li>
                        @endforelse
                    </ul>
                    <a class="button {{ ($plan->featured ?? false) ? 'button--primary' : 'button--secondary' }}" href="{{ route('register') }}">
                        {{ ((int) ($plan->monthly_price ?? 0)) === 0 ? 'Start free' : 'Choose '.$plan->name }}
                    </a>
                </article>
            @endforeach
        </div>

        <p class="mkt-lead" style="margin-top:28px">Need a walkthrough for a team or agency? <a href="{{ route('marketing.contact') }}" style="color:var(--primary);font-weight:650">Talk to us</a>.</p>
    </div>
</x-marketing-layout>

import { useState } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import Field from '../components/Field';
import { Button, ErrorBanner, Spinner } from '../components/ui';
import { useAuth } from '../context/AuthContext';
import { getErrorMessage, getFieldErrors } from '../lib/errors';

function LoginIcon({ name, className = 'h-5 w-5' }) {
  const icons = {
    shield: (
      <>
        <path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z" />
        <path d="m9 12 2 2 4-4" />
      </>
    ),
    arrow: (
      <>
        <path d="M5 12h14" />
        <path d="m12 5 7 7-7 7" />
      </>
    ),
    chart: (
      <>
        <path d="M3 3v18h18" />
        <path d="m19 9-5 5-4-4-5 5" />
      </>
    ),
    wallet: (
      <>
        <rect x="3" y="5" width="18" height="15" rx="3" />
        <path d="M3 9h18" />
        <path d="M16 14h.01" />
        <path d="M7 5V3h12" />
      </>
    ),
    eye: (
      <>
        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
        <circle cx="12" cy="12" r="3" />
      </>
    ),
    eyeOff: (
      <>
        <path d="m3 3 18 18" />
        <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8" />
        <path d="M9.9 5.2A11 11 0 0 1 12 5c6.5 0 10 7 10 7a14 14 0 0 1-3 3.8" />
        <path d="M6.6 6.6C3.6 8.5 2 12 2 12s3.5 7 10 7c1.3 0 2.5-.3 3.5-.7" />
      </>
    ),
  };

  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.7"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      aria-hidden="true"
    >
      {icons[name]}
    </svg>
  );
}

export default function LoginPage() {
  const { user, loading, login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  const [values, setValues] = useState({ email: '', password: '' });
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState('');
  const [busy, setBusy] = useState(false);
  const [showPassword, setShowPassword] = useState(false);

  const destination = location.state?.from?.pathname || '/';

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-[#080d1a] text-blue-400">
        <Spinner className="h-7 w-7" />
      </div>
    );
  }

  if (user) {
    return <Navigate to={destination} replace />;
  }

  const change = (name, value) => {
    setValues((current) => ({ ...current, [name]: value }));
    setErrors((current) => ({ ...current, [name]: undefined }));
    setFormError('');
  };

  const submit = async (event) => {
    event.preventDefault();
    setFormError('');

    const found = {};

    if (!values.email.trim()) {
      found.email = 'Enter your email address.';
    }

    if (!values.password) {
      found.password = 'Enter your password.';
    }

    setErrors(found);

    if (Object.keys(found).length > 0) return;

    setBusy(true);

    try {
      await login(values.email.trim(), values.password);
      navigate(destination, { replace: true });
    } catch (error) {
      const fieldErrors = getFieldErrors(error);
      setErrors(fieldErrors);

      if (Object.keys(fieldErrors).length === 0) {
        setFormError(getErrorMessage(error));
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="finance-login relative isolate flex min-h-screen items-center justify-center overflow-hidden bg-[#080d1a] px-4 py-10 text-white sm:px-6">
      {/* Animated ambient background */}
      <div className="pointer-events-none absolute inset-0 overflow-hidden">
        <div className="login-orb login-orb-one absolute -left-32 -top-32 h-[420px] w-[420px] rounded-full bg-blue-600/20 blur-[100px]" />
        <div className="login-orb login-orb-two absolute -bottom-40 -right-24 h-[460px] w-[460px] rounded-full bg-violet-600/15 blur-[110px]" />
        <div className="login-orb login-orb-three absolute left-1/2 top-1/3 h-72 w-72 rounded-full bg-cyan-500/10 blur-[100px]" />

        <div className="absolute inset-0 bg-[linear-gradient(rgba(148,163,184,0.035)_1px,transparent_1px),linear-gradient(90deg,rgba(148,163,184,0.035)_1px,transparent_1px)] bg-[size:48px_48px]" />

        <div className="login-grid-fade absolute inset-0 bg-[radial-gradient(ellipse_at_center,transparent_10%,#080d1a_85%)]" />
      </div>

      {/* Main layout */}
      <div className="relative z-10 grid w-full max-w-6xl items-center gap-12 lg:grid-cols-[1.1fr_0.9fr] lg:gap-20">
        {/* Brand and financial preview */}
        <section className="login-intro hidden lg:block">
          <div className="mb-12 flex items-center gap-3">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl border border-blue-400/30 bg-blue-500/15 shadow-lg shadow-blue-500/10">
              <LoginIcon name="chart" className="h-6 w-6 text-blue-300" />
            </div>

            <div>
              <p className="text-lg font-semibold tracking-tight">FinanceFlow</p>
              <p className="text-xs tracking-wide text-slate-500">
                PERSONAL FINANCE &amp; BUSINESS
              </p>
            </div>
          </div>

          <div className="max-w-xl">
            <div className="mb-5 inline-flex items-center gap-2 rounded-full border border-blue-400/20 bg-blue-400/[0.07] px-3 py-1.5 text-xs font-medium text-blue-300">
              <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400" />
              Your finances, in one place
            </div>

            <h1 className="text-5xl font-semibold leading-[1.12] tracking-tight xl:text-6xl">
              Take control of
              <span className="login-gradient-text mt-2 block">
                every shilling.
              </span>
            </h1>

            <p className="mt-6 max-w-md text-base leading-7 text-slate-400">
              Understand your spending, track your phone business profits,
              and build a clearer picture of your financial future.
            </p>
          </div>

          {/* Decorative finance preview; not live account data */}
          <div className="login-preview mt-12 max-w-lg rounded-3xl border border-white/[0.09] bg-white/[0.035] p-5 shadow-2xl shadow-black/20 backdrop-blur-xl">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-slate-400">Financial overview</p>
                <p className="mt-1 text-xs text-slate-600">
                  A clearer view of your money
                </p>
              </div>
              <div className="rounded-xl border border-white/[0.08] bg-white/[0.04] p-2.5 text-blue-300">
                <LoginIcon name="wallet" />
              </div>
            </div>

            <div className="mt-7 grid grid-cols-2 gap-3">
              <div className="rounded-2xl border border-white/[0.06] bg-[#0c1323]/80 p-4">
                <p className="text-xs text-slate-500">Business</p>
                <p className="mt-2 text-sm font-medium text-slate-200">
                  Sales &amp; profit
                </p>
                <div
                  className="mt-4 flex h-8 items-end gap-1.5"
                  aria-hidden="true"
                >
                  {[35, 55, 42, 75, 50, 90, 68, 100, 78, 88].map(
                    (height, i) => (
                      <div
                        key={i}
                        className="login-bar flex-1 rounded-t-sm bg-gradient-to-t from-blue-600/40 to-blue-400"
                        style={{
                          '--bar-height': `${height}%`,
                          '--bar-delay': `${i * 70}ms`,
                        }}
                      />
                    ),
                  )}
                </div>
              </div>

              <div className="rounded-2xl border border-white/[0.06] bg-[#0c1323]/80 p-4">
                <p className="text-xs text-slate-500">Personal</p>
                <p className="mt-2 text-sm font-medium text-slate-200">
                  Spending &amp; budgets
                </p>
                <div className="mt-4 flex items-center gap-3">
                  <div className="relative h-10 w-10 rounded-full border-[5px] border-emerald-400/80 border-r-slate-700 border-b-slate-700">
                    <div className="absolute inset-0 rounded-full border border-white/5" />
                  </div>
                  <div>
                    <p className="text-xs font-medium text-emerald-300">
                      Stay informed
                    </p>
                    <p className="mt-1 text-[10px] text-slate-500">
                      Track every expense
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <div className="mt-4 flex items-center gap-3 rounded-xl border border-white/[0.06] bg-white/[0.025] px-4 py-3">
              <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-400/10 text-emerald-300">
                <LoginIcon name="shield" className="h-4 w-4" />
              </div>
              <div>
                <p className="text-xs font-medium text-slate-200">
                  Your financial overview
                </p>
                <p className="mt-1 text-[11px] text-slate-500">
                  Organized, private, and under your control
                </p>
              </div>
            </div>
          </div>

          <p className="mt-8 text-xs text-slate-600">
            Make every financial decision count.
          </p>
        </section>

        {/* Login card */}
        <section className="login-card mx-auto w-full max-w-md">
          <div className="mb-8 flex items-center gap-3 lg:hidden">
            <div className="flex h-11 w-11 items-center justify-center rounded-2xl border border-blue-400/30 bg-blue-500/15">
              <LoginIcon name="chart" className="h-5 w-5 text-blue-300" />
            </div>
            <div>
              <p className="font-semibold tracking-tight">FinanceFlow</p>
              <p className="text-xs text-slate-500">Finance management</p>
            </div>
          </div>

          <div className="login-panel rounded-[28px] border border-white/[0.10] bg-[#101727]/90 p-6 shadow-[0_32px_100px_-35px_rgba(0,0,0,0.9)] backdrop-blur-2xl sm:p-9">
            <div className="mb-8">
              <div className="mb-5 flex h-12 w-12 items-center justify-center rounded-2xl border border-blue-400/20 bg-gradient-to-br from-blue-500/20 to-violet-500/20 text-blue-300">
                <LoginIcon name="shield" className="h-6 w-6" />
              </div>

              <p className="mb-2 text-xs font-semibold uppercase tracking-[0.2em] text-blue-300">
                Welcome back
              </p>

              <h2 className="text-3xl font-semibold tracking-tight text-white">
                Sign in to your account
              </h2>

              <p className="mt-3 text-sm leading-6 text-slate-400">
                Access your business performance and personal finances.
              </p>
            </div>

            {formError && (
              <div className="login-error mb-5" role="alert" aria-live="polite">
                <ErrorBanner message={formError} />
              </div>
            )}

            <form onSubmit={submit} noValidate>
              <div className="space-y-5">
                <div className="login-field">
                  <Field
                    label="Email address"
                    name="email"
                    type="email"
                    value={values.email}
                    onChange={change}
                    error={errors.email}
                    required
                  />
                </div>

                <div className="login-field login-field-delayed">
                  <Field
                    label="Password"
                    name="password"
                    type={showPassword ? 'text' : 'password'}
                    value={values.password}
                    onChange={change}
                    error={errors.password}
                    required
                  />

                  <button
                    type="button"
                    onClick={() => setShowPassword((visible) => !visible)}
                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                    aria-pressed={showPassword}
                    className="mt-2 inline-flex items-center gap-2 rounded-md text-xs text-slate-400 transition-colors hover:text-blue-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-400"
                  >
                    <LoginIcon
                      name={showPassword ? 'eyeOff' : 'eye'}
                      className="h-4 w-4"
                    />
                    {showPassword ? 'Hide password' : 'Show password'}
                  </button>
                </div>
              </div>

              <div className="mt-7">
                <Button
                  variant="primary"
                  type="submit"
                  loading={busy}
                  className="login-submit w-full justify-center rounded-xl py-3.5 text-sm font-semibold shadow-lg shadow-blue-600/20 transition-all duration-300 hover:shadow-blue-500/30"
                >
                  <span className="inline-flex items-center justify-center gap-2">
                    Sign in
                    {!busy && <LoginIcon name="arrow" className="h-4 w-4" />}
                  </span>
                </Button>
              </div>

              <div className="mt-6 flex items-start gap-2.5 rounded-xl border border-white/[0.06] bg-white/[0.025] p-3.5">
                <LoginIcon
                  name="shield"
                  className="mt-0.5 h-4 w-4 shrink-0 text-emerald-400"
                />
                <p className="text-xs leading-5 text-slate-500">
                  Your account is protected by your existing authentication
                  system. Never share your password with anyone.
                </p>
              </div>
            </form>
          </div>

          <p className="mt-6 text-center text-xs text-slate-600">
            Finance management, made clearer.
          </p>
        </section>
      </div>

      <style>{`
        .finance-login {
          font-family: Inter, ui-sans-serif, system-ui, -apple-system,
            BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .login-card {
          animation: login-entrance 700ms cubic-bezier(.2,.8,.2,1) both;
        }

        .login-panel {
          position: relative;
          isolation: isolate;
          overflow: hidden;
          transition:
            transform 300ms ease,
            border-color 300ms ease,
            box-shadow 300ms ease;
        }

        .login-panel::before {
          content: "";
          position: absolute;
          z-index: -1;
          top: -180px;
          right: -140px;
          width: 340px;
          height: 340px;
          border-radius: 50%;
          background: radial-gradient(
            circle,
            rgba(59, 130, 246, .13),
            transparent 70%
          );
          pointer-events: none;
        }

        .login-panel:hover {
          transform: translateY(-3px);
          border-color: rgba(147, 197, 253, .22);
          box-shadow: 0 36px 110px -35px rgba(0, 0, 0, .95);
        }

        .login-intro {
          animation: login-intro-entrance 850ms cubic-bezier(.2,.8,.2,1) both;
        }

        .login-preview {
          animation: preview-entrance 900ms 180ms cubic-bezier(.2,.8,.2,1) both;
          transition:
            transform 350ms ease,
            border-color 350ms ease,
            background-color 350ms ease;
        }

        .login-preview:hover {
          transform: translateY(-4px);
          border-color: rgba(147, 197, 253, .2);
        }

        .login-gradient-text {
          background: linear-gradient(100deg, #93c5fd 5%, #818cf8 48%, #c4b5fd 90%);
          -webkit-background-clip: text;
          background-clip: text;
          color: transparent;
        }

        .login-orb-one {
          animation: orb-drift-one 16s ease-in-out infinite alternate;
        }

        .login-orb-two {
          animation: orb-drift-two 19s ease-in-out infinite alternate;
        }

        .login-orb-three {
          animation: orb-drift-three 14s ease-in-out infinite alternate;
        }

        .login-grid-fade {
          pointer-events: none;
        }

        .login-field {
          animation: field-entrance 550ms 180ms both;
        }

        .login-field-delayed {
          animation-delay: 260ms;
        }

        .login-error {
          transform-origin: top;
          animation: error-entrance 300ms ease-out both;
        }

        .login-submit {
          position: relative;
          overflow: hidden;
        }

        .login-submit:not(:disabled):hover {
          transform: translateY(-1px);
        }

        .login-submit:not(:disabled):active {
          transform: translateY(0) scale(.99);
        }

        .login-submit:not(:disabled)::after {
          content: "";
          position: absolute;
          inset: 0;
          background: linear-gradient(
            110deg,
            transparent 20%,
            rgba(255, 255, 255, .12) 45%,
            transparent 70%
          );
          transform: translateX(-120%);
          transition: transform 650ms ease;
          pointer-events: none;
        }

        .login-submit:not(:disabled):hover::after {
          transform: translateX(120%);
        }

        .login-bar {
          height: var(--bar-height);
          transform-origin: bottom;
          animation: bar-entrance 750ms var(--bar-delay) cubic-bezier(.2,.8,.2,1) both;
        }

        @keyframes login-entrance {
          from { opacity: 0; transform: translateY(24px) scale(.985); }
          to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes login-intro-entrance {
          from { opacity: 0; transform: translateX(-22px); }
          to { opacity: 1; transform: translateX(0); }
        }

        @keyframes preview-entrance {
          from { opacity: 0; transform: translateY(18px); }
          to { opacity: 1; transform: translateY(0); }
        }

        @keyframes field-entrance {
          from { opacity: 0; transform: translateY(8px); }
          to { opacity: 1; transform: translateY(0); }
        }

        @keyframes error-entrance {
          from { opacity: 0; transform: translateY(-6px) scale(.98); }
          to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes orb-drift-one {
          from { transform: translate3d(0,0,0); }
          to { transform: translate3d(45px,30px,0); }
        }

        @keyframes orb-drift-two {
          from { transform: translate3d(0,0,0); }
          to { transform: translate3d(-35px,-28px,0); }
        }

        @keyframes orb-drift-three {
          from { transform: translate3d(-25px,15px,0); }
          to { transform: translate3d(30px,-25px,0); }
        }

        @keyframes bar-entrance {
          from { transform: scaleY(.1); opacity: .25; }
          to { transform: scaleY(1); opacity: 1; }
        }

        @media (prefers-reduced-motion: reduce) {
          .finance-login *,
          .finance-login *::before,
          .finance-login *::after {
            animation-duration: .01ms !important;
            animation-iteration-count: 1 !important;
            scroll-behavior: auto !important;
            transition-duration: .01ms !important;
          }

          .login-panel:hover,
          .login-preview:hover {
            transform: none;
          }
        }
      `}</style>
    </main>
  );
}
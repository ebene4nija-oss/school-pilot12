import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/providers.dart';
import '../../core/ui/widgets.dart';

class SignInScreen extends ConsumerStatefulWidget {
  const SignInScreen({super.key});

  @override
  ConsumerState<SignInScreen> createState() => _SignInScreenState();
}

class _SignInScreenState extends ConsumerState<SignInScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();

  bool _obscure = true;
  bool _busy = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};

  /// Administrators with TOTP enabled get a second step. Teachers, students and
  /// parents never see it — the backend only demands it for admin roles.
  bool _needsTwoFactor = false;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
      _fieldErrors = const {};
    });

    try {
      await ref.read(authControllerProvider.notifier).signIn(
            email: _email.text,
            password: _password.text,
            twoFactorCode: _needsTwoFactor ? _code.text : null,
          );
      // The router redirects on the state change; nothing to do here.
    } on TwoFactorRequired {
      setState(() {
        _needsTwoFactor = true;
        _busy = false;
      });
    } catch (error) {
      final failure = asApiException(error);
      setState(() {
        _busy = false;
        _fieldErrors = failure.fieldErrors;
        _error = failure.isRateLimited
            ? 'Too many attempts. Try again in a minute.'
            : failure.isOffline
                ? 'No connection. You need to be online to sign in.'
                : failure.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final schoolCode = ref.watch(authControllerProvider).schoolCode ?? '';

    return Scaffold(
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(AppSpacing.lg),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SizedBox(height: AppSpacing.md),
              _SchoolChip(
                code: schoolCode,
                onChange: () => _changeSchool(context),
              ),
              const SizedBox(height: AppSpacing.xl),
              Text(
                _needsTwoFactor ? 'Enter your 6-digit code' : 'Sign in',
                style: AppText.headlineMd,
              ),
              const SizedBox(height: AppSpacing.sm),
              Text(
                _needsTwoFactor
                    ? 'From your authenticator app. Required for administrator accounts.'
                    : 'Use the account your school created for you.',
                style:
                    AppText.bodyMd.copyWith(color: AppColors.onSurfaceVariant),
              ),
              const SizedBox(height: AppSpacing.lg),
              if (_needsTwoFactor)
                _TwoFactorField(controller: _code, onSubmit: _submit)
              else ...[
                TextField(
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  autocorrect: false,
                  textInputAction: TextInputAction.next,
                  decoration: InputDecoration(
                    labelText: 'Email',
                    errorText: _fieldErrors['email'],
                    prefixIcon: const Icon(Icons.mail_outline),
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                TextField(
                  controller: _password,
                  obscureText: _obscure,
                  textInputAction: TextInputAction.done,
                  onSubmitted: (_) => _submit(),
                  decoration: InputDecoration(
                    labelText: 'Password',
                    errorText: _fieldErrors['password'],
                    prefixIcon: const Icon(Icons.lock_outline),
                    suffixIcon: IconButton(
                      icon: Icon(
                        _obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined,
                      ),
                      onPressed: () => setState(() => _obscure = !_obscure),
                    ),
                  ),
                ),
                Align(
                  alignment: Alignment.centerRight,
                  child: TextButton(
                    onPressed: _showForgotPassword,
                    child: const Text('Forgot password?'),
                  ),
                ),
              ],
              if (_error != null) ...[
                const SizedBox(height: AppSpacing.sm),
                _ErrorNotice(_error!),
              ],
              const SizedBox(height: AppSpacing.lg),
              FilledButton(
                onPressed: _busy ? null : _submit,
                child: _busy
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: AppColors.onAction,
                        ),
                      )
                    : Text(_needsTwoFactor ? 'Verify' : 'Sign in'),
              ),
              if (_needsTwoFactor) ...[
                const SizedBox(height: AppSpacing.sm),
                TextButton(
                  onPressed: _busy
                      ? null
                      : () => setState(() {
                            _needsTwoFactor = false;
                            _code.clear();
                            _error = null;
                          }),
                  child: const Text('Back to sign in'),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  /// There is no self-service reset on the backend — only the admin-initiated
  /// `POST /users/{id}/reset-password` (gap G2). Saying so plainly beats a
  /// button that goes nowhere.
  void _showForgotPassword() {
    showDialog<void>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Forgot your password?'),
        content: const Text(
          'Your school office can reset it for you from the admin portal.\n\n'
          'Self-service reset is not available yet.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Close'),
          ),
        ],
      ),
    );
  }

  Future<void> _changeSchool(BuildContext context) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Change school?'),
        content: const Text(
          'You will need to enter a school code again before you can sign in.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Change'),
          ),
        ],
      ),
    );

    if (confirmed ?? false) {
      await ref.read(authControllerProvider.notifier).setSchoolCode('');
    }
  }
}

class _SchoolChip extends StatelessWidget {
  const _SchoolChip({required this.code, required this.onChange});

  final String code;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    return OutlinedCard(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.sm,
      ),
      child: Row(
        children: [
          const Icon(Icons.domain_outlined, color: AppColors.primaryContainer),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Text(
              code.isEmpty ? 'No school selected' : code,
              style: AppText.labelLg,
            ),
          ),
          TextButton(
            onPressed: onChange,
            style: TextButton.styleFrom(minimumSize: const Size(0, 36)),
            child: const Text('Change'),
          ),
        ],
      ),
    );
  }
}

class _TwoFactorField extends StatelessWidget {
  const _TwoFactorField({required this.controller, required this.onSubmit});

  final TextEditingController controller;
  final VoidCallback onSubmit;

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: controller,
      keyboardType: TextInputType.number,
      textAlign: TextAlign.center,
      maxLength: 6,
      autofocus: true,
      style: AppText.numericAt(28, weight: FontWeight.w700),
      inputFormatters: [FilteringTextInputFormatter.digitsOnly],
      onSubmitted: (_) => onSubmit(),
      decoration: const InputDecoration(
        counterText: '',
        hintText: '000000',
      ),
    );
  }
}

class _ErrorNotice extends StatelessWidget {
  const _ErrorNotice(this.message);

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.sm + 4),
      decoration: BoxDecoration(
        color: AppColors.errorContainer,
        borderRadius: AppRadius.cardRadius,
      ),
      child: Row(
        children: [
          const Icon(Icons.error_outline,
              size: 18, color: AppColors.onErrorContainer),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Text(
              message,
              style: AppText.bodyMd
                  .copyWith(color: AppColors.onErrorContainer),
            ),
          ),
        ],
      ),
    );
  }
}
